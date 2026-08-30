<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Enums\FixtureState;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

trait SyncsMatchData
{
    /** @var list<string> */
    private const array KNOWN_WORLDCUP26_STATUS_NAMES = [
        'STATUS_SCHEDULED',
        'STATUS_FIRST_HALF',
        'STATUS_HALFTIME',
        'STATUS_SECOND_HALF',
        'STATUS_FULL_TIME',
    ];

    /**
     * @param  Collection<int, Fixture>  $fixtures
     * @return array{synced: int, unresolved: list<string>}
     *
     * @throws Throwable
     */
    private function syncMatchDataForFixtures(Collection $fixtures, Worldcup26Connector $connector, LaLigaFantasyConnector $fantasyConnector): array
    {
        $synced = 0;
        $unresolved = [];

        foreach ($fixtures as $fixture) {
            try {
                $event = $connector->getEvent($fixture->match_data_id)->throw()->json();
            } catch (FatalRequestException|RequestException|JsonException $exception) {
                Log::warning("Failed to sync match data for fixture {$fixture->id}: {$exception->getMessage()}");
                $this->warn("Skipped fixture #{$fixture->id}: {$exception->getMessage()}");

                continue;
            }

            DB::transaction(function () use ($fixture, $event, &$unresolved): void {
                $this->syncFixture($fixture, $event);
                $unresolved = [...$unresolved, ...$this->syncLineups($fixture, $event)];
                $this->syncEvents($fixture, $event);
            });

            $this->fillFantasyScores($fixture, $fantasyConnector);

            $synced++;
        }

        return ['synced' => $synced, 'unresolved' => $unresolved];
    }

    private function fillFantasyScores(Fixture $fixture, LaLigaFantasyConnector $connector): void
    {
        FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->whereNotNull('player_id')
            ->with('player')
            ->get()
            ->each(function (FixtureLineup $lineup) use ($fixture, $connector): void {
                $fantasyId = $lineup->player?->fantasy_id;

                if ($fantasyId === null) {
                    return;
                }

                try {
                    $playerData = $connector->getPlayer($fantasyId)->throw()->json();
                } catch (FatalRequestException|RequestException|JsonException $exception) {
                    Log::warning("Failed to fetch Fantasy stats for player {$fantasyId} (fixture {$fixture->id}): {$exception->getMessage()}");

                    return;
                }

                $playerStats = is_array($playerData['playerStats'] ?? null) ? $playerData['playerStats'] : [];

                $weekStats = collect($playerStats)->first(
                    fn ($stat): bool => is_array($stat) && ($stat['weekNumber'] ?? null) === $fixture->week_number,
                );

                if (!is_array($weekStats)) {
                    return;
                }

                $lineup->update([
                    'fantasy_points' => isset($weekStats['totalPoints']) ? (int) $weekStats['totalPoints'] : null,
                    'fantasy_stats' => is_array($weekStats['stats'] ?? null) ? $weekStats['stats'] : null,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function syncFixture(Fixture $fixture, array $event): void
    {
        $competition = $event['header']['competitions'][0] ?? [];
        $statusName = (string) ($competition['status']['type']['name'] ?? '');
        $competitors = is_array($competition['competitors'] ?? null) ? array_values($competition['competitors']) : [];
        $rosters = is_array($event['rosters'] ?? null) ? array_values($event['rosters']) : [];

        if (!in_array($statusName, self::KNOWN_WORLDCUP26_STATUS_NAMES, true)) {
            Log::warning("Unmapped worldcup26 status name: {$statusName} (fixture {$fixture->id})");
        }

        $fixture->update([
            'state' => FixtureState::fromWorldcup26Name($statusName),
            'local_score' => $this->scoreFor($competitors, 'home'),
            'guest_score' => $this->scoreFor($competitors, 'away'),
            'local_formation' => $this->formationFor($rosters, 'home'),
            'guest_formation' => $this->formationFor($rosters, 'away'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $competitors
     */
    private function scoreFor(array $competitors, string $homeAway): ?int
    {
        foreach ($competitors as $competitor) {
            if (($competitor['homeAway'] ?? null) === $homeAway) {
                return isset($competitor['score']) ? (int) $competitor['score'] : null;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rosters
     */
    private function formationFor(array $rosters, string $homeAway): ?string
    {
        foreach ($rosters as $roster) {
            if (($roster['homeAway'] ?? null) === $homeAway) {
                return isset($roster['formation']) ? (string) $roster['formation'] : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function syncLineups(Fixture $fixture, array $event): array
    {
        $rosters = is_array($event['rosters'] ?? null) ? $event['rosters'] : [];
        $unresolved = [];

        FixtureLineup::query()->where('fixture_id', $fixture->id)->whereNull('player_id')->delete();

        foreach ($rosters as $rosterEntry) {
            $teamMatchDataId = (int) ($rosterEntry['team']['id'] ?? 0);
            $team = Team::query()->where('match_data_id', $teamMatchDataId)->first();

            if ($team === null) {
                continue;
            }

            $rosterPlayers = is_array($rosterEntry['roster'] ?? null) ? $rosterEntry['roster'] : [];

            foreach ($rosterPlayers as $rosterPlayer) {
                $athleteMatchDataId = (int) ($rosterPlayer['athlete']['id'] ?? 0);
                $player = Player::query()
                    ->where('match_data_id', $athleteMatchDataId)
                    ->first();

                if ($player === null) {
                    $unresolved[] = (string) ($rosterPlayer['athlete']['displayName'] ?? $athleteMatchDataId);
                    $this->createUnresolvedLineup($fixture, $team, $rosterPlayer);

                    continue;
                }

                $this->upsertLineup($fixture, $team, $player, $rosterPlayer);
            }
        }

        return $unresolved;
    }

    /**
     * @param  array<string, mixed>  $rosterPlayer
     */
    private function createUnresolvedLineup(Fixture $fixture, Team $team, array $rosterPlayer): void
    {
        FixtureLineup::query()->create([
            'fixture_id' => $fixture->id,
            'player_id' => null,
            'unresolved_name' => (string) ($rosterPlayer['athlete']['displayName'] ?? ''),
            'team_id' => $team->id,
            'starter' => (bool) ($rosterPlayer['starter'] ?? false),
            'position' => (string) ($rosterPlayer['position']['displayName'] ?? ''),
            'jersey' => (string) ($rosterPlayer['jersey'] ?? ''),
            'subbed_in' => (bool) ($rosterPlayer['subbedIn'] ?? false),
            'subbed_out' => (bool) ($rosterPlayer['subbedOut'] ?? false),
            'counterpart_player_id' => null,
            'sub_minute' => $this->subMinute($rosterPlayer),
            'stats' => is_array($rosterPlayer['stats'] ?? null) ? $rosterPlayer['stats'] : [],
        ]);
    }

    /**
     * @param  array<string, mixed>  $rosterPlayer
     */
    private function upsertLineup(Fixture $fixture, Team $team, Player $player, array $rosterPlayer): void
    {
        $subbedIn = (bool) ($rosterPlayer['subbedIn'] ?? false);
        $subbedOut = (bool) ($rosterPlayer['subbedOut'] ?? false);

        $counterpartMatchDataId = match (true) {
            $subbedIn => $rosterPlayer['subbedInFor']['athlete']['id'] ?? null,
            $subbedOut => $rosterPlayer['subbedOutFor']['athlete']['id'] ?? null,
            default => null,
        };

        $counterpartPlayer = $counterpartMatchDataId !== null
            ? Player::query()->where('match_data_id', (int) $counterpartMatchDataId)->first()
            : null;

        FixtureLineup::query()->updateOrCreate(
            ['fixture_id' => $fixture->id, 'player_id' => $player->id],
            [
                'team_id' => $team->id,
                'starter' => (bool) ($rosterPlayer['starter'] ?? false),
                'position' => (string) ($rosterPlayer['position']['displayName'] ?? ''),
                'jersey' => (string) ($rosterPlayer['jersey'] ?? ''),
                'subbed_in' => $subbedIn,
                'subbed_out' => $subbedOut,
                'counterpart_player_id' => $counterpartPlayer?->id,
                'sub_minute' => $this->subMinute($rosterPlayer),
                'stats' => is_array($rosterPlayer['stats'] ?? null) ? $rosterPlayer['stats'] : [],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $rosterPlayer
     */
    private function subMinute(array $rosterPlayer): ?int
    {
        $plays = is_array($rosterPlayer['plays'] ?? null) ? $rosterPlayer['plays'] : [];

        foreach ($plays as $play) {
            if (($play['substitution'] ?? false) === true) {
                return $this->minuteFromClock((string) ($play['clock']['displayValue'] ?? ''));
            }
        }

        return null;
    }

    private function minuteFromClock(string $displayValue): ?int
    {
        return preg_match('/^(\d+)/', $displayValue, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function syncEvents(Fixture $fixture, array $event): void
    {
        FixtureEvent::query()->where('fixture_id', $fixture->id)->delete();

        $keyEvents = is_array($event['keyEvents'] ?? null) ? $event['keyEvents'] : [];

        foreach ($keyEvents as $keyEvent) {
            $type = $this->eventType($keyEvent);

            if ($type === null) {
                Log::info('Unmapped worldcup26 event type: '.($keyEvent['type']['text'] ?? 'unknown'));

                continue;
            }

            $teamMatchDataId = (int) ($keyEvent['team']['id'] ?? 0);
            $team = Team::query()->where('match_data_id', $teamMatchDataId)->first();

            if ($team === null) {
                Log::warning("Unmapped worldcup26 team match_data_id {$teamMatchDataId} for fixture {$fixture->id}: dropping event");

                continue;
            }

            $athletes = is_array($keyEvent['athletesInvolved'] ?? null) ? $keyEvent['athletesInvolved'] : [];
            $athleteMatchDataId = isset($athletes[0]['id']) ? (int) $athletes[0]['id'] : null;
            $player = $athleteMatchDataId !== null
                ? Player::query()->where('match_data_id', $athleteMatchDataId)->first()
                : null;

            FixtureEvent::query()->create([
                'fixture_id' => $fixture->id,
                'team_id' => $team->id,
                'player_id' => $player?->id,
                'type' => $type,
                'minute' => $this->minuteFromClock((string) ($keyEvent['clock']['displayValue'] ?? '')) ?? 0,
                'is_own_goal' => (bool) ($keyEvent['ownGoal'] ?? false),
                'is_penalty' => (bool) ($keyEvent['penaltyKick'] ?? false),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $keyEvent
     */
    private function eventType(array $keyEvent): ?string
    {
        return match (true) {
            ($keyEvent['scoringPlay'] ?? false) === true => 'goal',
            ($keyEvent['redCard'] ?? false) === true => 'red_card',
            ($keyEvent['yellowCard'] ?? false) === true => 'yellow_card',
            ($keyEvent['penaltyKick'] ?? false) === true => 'penalty_missed',
            default => null,
        };
    }
}
