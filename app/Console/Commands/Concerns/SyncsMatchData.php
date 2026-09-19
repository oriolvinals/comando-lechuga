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
        $fantasyPlayerCache = [];
        $warnings = [];

        $this->output->progressStart($fixtures->count());

        foreach ($fixtures as $fixture) {
            $this->output->progressAdvance();

            try {
                $event = $connector->getEvent($fixture->wc26_id)->throw()->json();
            } catch (FatalRequestException|RequestException|JsonException $exception) {
                Log::warning("Failed to sync match data for fixture {$fixture->id}: {$exception->getMessage()}");
                $warnings[] = "Skipped fixture #{$fixture->id}: {$exception->getMessage()}";

                continue;
            }

            $statusName = (string) ($event['header']['competitions'][0]['status']['type']['name'] ?? '');
            $state = FixtureState::fromWorldcup26Name($statusName);

            if ($state === null) {
                Log::warning("Unmapped worldcup26 status name: {$statusName} (fixture {$fixture->id})");

                continue;
            }

            DB::transaction(function () use ($fixture, $event, $state, &$unresolved): void {
                $this->syncFixture($fixture, $event, $state);
                $unresolved = [...$unresolved, ...$this->syncLineups($fixture, $event)];
                $this->syncEvents($fixture, $event);
            });

            $this->fillFantasyScores($fixture, $fantasyConnector, $fantasyPlayerCache);

            $synced++;
        }

        $this->output->progressFinish();

        foreach ($warnings as $warning) {
            $this->warn($warning);
        }

        return ['synced' => $synced, 'unresolved' => $unresolved];
    }

    /**
     * @param  array<int, array<string, mixed>|false>  $fantasyPlayerCache  fantasy_id => decoded getPlayer() response, or false if the fetch failed (cached to avoid retrying a known-bad id within the same run). Memoized across the whole syncMatchDataForFixtures() run so a player who appears in many fixtures (e.g. across a full-season backfill) is only fetched from Fantasy once.
     */
    private function fillFantasyScores(Fixture $fixture, LaLigaFantasyConnector $connector, array &$fantasyPlayerCache): void
    {
        FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->whereNotNull('player_id')
            ->with('player')
            ->get()
            ->each(function (FixtureLineup $lineup) use ($fixture, $connector, &$fantasyPlayerCache): void {
                $fantasyId = $lineup->player?->fantasy_id;

                if ($fantasyId === null) {
                    return;
                }

                if (!array_key_exists($fantasyId, $fantasyPlayerCache)) {
                    try {
                        $fantasyPlayerCache[$fantasyId] = $connector->getPlayer($fantasyId)->throw()->json();
                    } catch (FatalRequestException|RequestException|JsonException $exception) {
                        Log::warning("Failed to fetch Fantasy stats for player {$fantasyId} (fixture {$fixture->id}): {$exception->getMessage()}");
                        $fantasyPlayerCache[$fantasyId] = false;
                    }
                }

                $playerData = $fantasyPlayerCache[$fantasyId];

                if ($playerData === false) {
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
    private function syncFixture(Fixture $fixture, array $event, FixtureState $state): void
    {
        $competition = $event['header']['competitions'][0] ?? [];
        $displayClock = isset($competition['status']['displayClock']) ? (string) $competition['status']['displayClock'] : null;
        $competitors = is_array($competition['competitors'] ?? null) ? array_values($competition['competitors']) : [];
        $rosters = is_array($event['rosters'] ?? null) ? array_values($event['rosters']) : [];

        $fixture->update([
            'state' => $state,
            'display_clock' => $displayClock,
            'local_score' => $this->scoreFor($competitors, 'home'),
            'guest_score' => $this->scoreFor($competitors, 'away'),
            'local_formation' => $this->formationFor($rosters, 'home'),
            'guest_formation' => $this->formationFor($rosters, 'away'),
            'local_color' => $this->colorFor($competitors, 'home', 'color'),
            'local_alternate_color' => $this->colorFor($competitors, 'home', 'alternateColor'),
            'guest_color' => $this->colorFor($competitors, 'away', 'color'),
            'guest_alternate_color' => $this->colorFor($competitors, 'away', 'alternateColor'),
            ...$this->matchDetailsFor($fixture, $event, $competition),
            ...$this->boxscoreStatsFor($fixture, $event),
        ]);
    }

    /**
     * Venue, attendance and referee only exist on the payload once worldcup26
     * has them, so a sync that finds them missing keeps whatever an earlier
     * one already stored instead of blanking it.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, mixed>  $competition
     * @return array{venue: string, venue_city: string, attendance: int|null, referee: string}
     */
    private function matchDetailsFor(Fixture $fixture, array $event, array $competition): array
    {
        $venue = is_array($competition['venue'] ?? null) ? $competition['venue'] : [];
        $venueName = (string) ($venue['fullName'] ?? '');
        $venueCity = (string) ($venue['address']['city'] ?? '');
        $referee = $this->refereeName($event);

        return [
            'venue' => $venueName !== '' ? $venueName : $fixture->venue,
            'venue_city' => $venueCity !== '' ? $venueCity : $fixture->venue_city,
            'attendance' => isset($competition['attendance']) ? (int) $competition['attendance'] : $fixture->attendance,
            'referee' => $referee !== '' ? $referee : $fixture->referee,
        ];
    }

    /**
     * The boxscore only reports the stats worldcup26 tracks at team level;
     * like the venue details, a sync that finds it missing keeps what an
     * earlier one already stored.
     *
     * @param  array<string, mixed>  $event
     * @return array{local_possession: float|null, guest_possession: float|null, local_corners: int|null, guest_corners: int|null, local_key_passes: int|null, guest_key_passes: int|null}
     */
    private function boxscoreStatsFor(Fixture $fixture, array $event): array
    {
        $int = fn (?float $value, ?int $current): ?int => $value === null ? $current : (int) $value;

        return [
            'local_possession' => $this->boxscoreStat($event, 'home', 'possessionPct') ?? $fixture->local_possession,
            'guest_possession' => $this->boxscoreStat($event, 'away', 'possessionPct') ?? $fixture->guest_possession,
            'local_corners' => $int($this->boxscoreStat($event, 'home', 'wonCorners'), $fixture->local_corners),
            'guest_corners' => $int($this->boxscoreStat($event, 'away', 'wonCorners'), $fixture->guest_corners),
            'local_key_passes' => $int($this->boxscoreStat($event, 'home', 'shotAssists'), $fixture->local_key_passes),
            'guest_key_passes' => $int($this->boxscoreStat($event, 'away', 'shotAssists'), $fixture->guest_key_passes),
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function boxscoreStat(array $event, string $homeAway, string $name): ?float
    {
        $teams = is_array($event['boxscore']['teams'] ?? null) ? $event['boxscore']['teams'] : [];

        foreach ($teams as $team) {
            if (($team['homeAway'] ?? null) !== $homeAway) {
                continue;
            }

            foreach (is_array($team['statistics'] ?? null) ? $team['statistics'] : [] as $stat) {
                $value = $stat['displayValue'] ?? null;

                if (($stat['name'] ?? null) === $name && is_numeric($value)) {
                    return (float) $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function refereeName(array $event): string
    {
        $officials = is_array($event['gameInfo']['officials'] ?? null) ? $event['gameInfo']['officials'] : [];

        foreach ($officials as $official) {
            if (($official['position']['name'] ?? null) === 'Referee') {
                return (string) ($official['fullName'] ?? '');
            }
        }

        return '';
    }

    /**
     * The kit colors worldcup26 reports for this specific fixture — a team
     * can play in a different kit from match to match (e.g. an away kit to
     * avoid a color clash), so this is stored per fixture, not on Team.
     *
     * @param  list<array<string, mixed>>  $competitors
     */
    private function colorFor(array $competitors, string $homeAway, string $key): ?string
    {
        foreach ($competitors as $competitor) {
            if (($competitor['homeAway'] ?? null) !== $homeAway) {
                continue;
            }

            $teamData = is_array($competitor['team'] ?? null) ? $competitor['team'] : [];

            return isset($teamData[$key]) ? (string) $teamData[$key] : null;
        }

        return null;
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
        $currentPlayerIds = [];

        FixtureLineup::query()->where('fixture_id', $fixture->id)->whereNull('player_id')->delete();

        foreach ($rosters as $rosterEntry) {
            $teamWc26Id = (int) ($rosterEntry['team']['id'] ?? 0);
            $team = Team::query()->where('wc26_id', $teamWc26Id)->first();

            if ($team === null) {
                continue;
            }

            $rosterPlayers = is_array($rosterEntry['roster'] ?? null) ? $rosterEntry['roster'] : [];

            foreach ($rosterPlayers as $rosterPlayer) {
                $athleteWc26Id = (int) ($rosterPlayer['athlete']['id'] ?? 0);
                $player = Player::query()
                    ->where('wc26_id', $athleteWc26Id)
                    ->first();

                if ($player === null) {
                    $unresolved[] = (string) ($rosterPlayer['athlete']['displayName'] ?? $athleteWc26Id);
                    $this->createUnresolvedLineup($fixture, $team, $rosterPlayer, $athleteWc26Id);

                    continue;
                }

                $this->upsertLineup($fixture, $team, $player, $rosterPlayer, $athleteWc26Id);
                $currentPlayerIds[] = $player->id;
            }
        }

        // Prune resolved rows for players worldcup26 no longer lists in this fixture's
        // roster — e.g. a provisional lineup got corrected before kickoff. The unresolved
        // wipe above only ever covered player_id IS NULL rows; this covers the rest.
        FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->whereNotNull('player_id')
            ->whereNotIn('player_id', $currentPlayerIds)
            ->delete();

        return $unresolved;
    }

    /**
     * @param  array<string, mixed>  $rosterPlayer
     */
    private function createUnresolvedLineup(Fixture $fixture, Team $team, array $rosterPlayer, int $athleteWc26Id): void
    {
        FixtureLineup::query()->create([
            'fixture_id' => $fixture->id,
            'player_id' => null,
            'unresolved_name' => (string) ($rosterPlayer['athlete']['displayName'] ?? ''),
            'wc26_id' => $athleteWc26Id,
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
    private function upsertLineup(Fixture $fixture, Team $team, Player $player, array $rosterPlayer, int $athleteWc26Id): void
    {
        $subbedIn = (bool) ($rosterPlayer['subbedIn'] ?? false);
        $subbedOut = (bool) ($rosterPlayer['subbedOut'] ?? false);

        $counterpartWc26Id = match (true) {
            $subbedIn => $rosterPlayer['subbedInFor']['athlete']['id'] ?? null,
            $subbedOut => $rosterPlayer['subbedOutFor']['athlete']['id'] ?? null,
            default => null,
        };

        $counterpartPlayer = $counterpartWc26Id !== null
            ? Player::query()->where('wc26_id', (int) $counterpartWc26Id)->first()
            : null;

        FixtureLineup::query()->updateOrCreate(
            ['fixture_id' => $fixture->id, 'player_id' => $player->id],
            [
                'wc26_id' => $athleteWc26Id,
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

            $teamWc26Id = (int) ($keyEvent['team']['id'] ?? 0);
            $team = Team::query()->where('wc26_id', $teamWc26Id)->first();

            if ($team === null) {
                Log::warning("Unmapped worldcup26 team wc26_id {$teamWc26Id} for fixture {$fixture->id}: dropping event");

                continue;
            }

            $athletes = $this->eventAthletes($keyEvent);

            if ($athletes === []) {
                // No athlete data at all — typically a card against a coach/staff
                // member rather than a player. We'd have no name to show for it
                // either way, so drop it instead of creating a blank event.
                continue;
            }

            $athleteWc26Id = isset($athletes[0]['id']) ? (int) $athletes[0]['id'] : null;
            $player = $athleteWc26Id !== null
                ? Player::query()->where('wc26_id', $athleteWc26Id)->first()
                : null;

            $typeSlug = (string) ($keyEvent['type']['type'] ?? '');

            FixtureEvent::query()->create([
                'fixture_id' => $fixture->id,
                'team_id' => $team->id,
                'player_id' => $player?->id,
                'wc26_id' => $athleteWc26Id,
                'unresolved_name' => $player === null ? (isset($athletes[0]['displayName']) ? (string) $athletes[0]['displayName'] : null) : null,
                'type' => $type,
                'minute' => $this->minuteFromClock((string) ($keyEvent['clock']['displayValue'] ?? '')) ?? 0,
                'is_own_goal' => (bool) ($keyEvent['ownGoal'] ?? $typeSlug === 'own-goal'),
                'is_penalty' => (bool) ($keyEvent['penaltyKick'] ?? str_contains($typeSlug, 'penalty')),
            ]);
        }
    }

    /**
     * worldcup26 sends two different shapes for the same athlete depending on
     * match status: a flat `athletesInvolved` array once the match is final,
     * or `participants[].athlete` while it's still live/in-progress.
     *
     * @param  array<string, mixed>  $keyEvent
     * @return list<array<string, mixed>>
     */
    private function eventAthletes(array $keyEvent): array
    {
        if (is_array($keyEvent['athletesInvolved'] ?? null)) {
            return array_values(array_filter(
                $keyEvent['athletesInvolved'],
                fn (mixed $athlete): bool => is_array($athlete),
            ));
        }

        $participants = is_array($keyEvent['participants'] ?? null) ? $keyEvent['participants'] : [];

        return array_values(array_filter(array_map(
            fn (mixed $participant): ?array => is_array($participant['athlete'] ?? null) ? $participant['athlete'] : null,
            $participants,
        )));
    }

    /**
     * Mirrors eventAthletes(): the finished-match shape exposes boolean flags
     * (redCard/yellowCard/penaltyKick), the live shape only exposes a machine
     * slug in type.type (e.g. "yellow-card") and no flags at all.
     *
     * @param  array<string, mixed>  $keyEvent
     */
    private function eventType(array $keyEvent): ?string
    {
        if (($keyEvent['scoringPlay'] ?? false) === true) {
            return 'goal';
        }

        if (array_key_exists('redCard', $keyEvent)) {
            return match (true) {
                ($keyEvent['redCard'] ?? false) === true => 'red_card',
                ($keyEvent['yellowCard'] ?? false) === true => 'yellow_card',
                ($keyEvent['penaltyKick'] ?? false) === true => 'penalty_missed',
                default => null,
            };
        }

        $slug = (string) ($keyEvent['type']['type'] ?? '');

        return match (true) {
            str_contains($slug, 'red-card') => 'red_card',
            str_contains($slug, 'yellow-card') => 'yellow_card',
            str_contains($slug, 'penalty') => 'penalty_missed',
            default => null,
        };
    }
}
