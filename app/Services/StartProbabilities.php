<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the match, team and manager fichas show about who starts:
 * FútbolFantasy's start probabilities for a fixture or — once there is one —
 * the confirmed lineup. Confirmed lineups come from worldcup26
 * (`fixture_lineups`) first and from FF's "Alineación confirmada"
 * (`confirmed_starter`) only while worldcup26 has none; when both exist,
 * worldcup26 wins.
 *
 * Rows are never deleted after kickoff — they keep the last predicted %
 * behind the "Sorpresa / Se cae · era N %" marks. The match ficha just
 * stops asking once its fixture has kicked off.
 *
 * @phpstan-type StartEntry array{player: Player, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null}
 * @phpstan-type StartTeamBlock array{fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null, is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, players: list<StartEntry>}
 * @phpstan-type PlayerNextStart array{fixture_id: int, week_number: int, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, confirmed_source: 'worldcup26'|'futbolfantasy'|null, is_stale: bool, fetched_at: string|null, source_url: string, team_short_name: string}
 */
class StartProbabilities
{
    use AttachesCurrentPlayerSeason;

    /** Data older than this is shown as stale ("Datos de hace N días"). */
    public const int STALE_AFTER_HOURS = 48;

    /**
     * Both sides of a fixture that hasn't kicked off — null once it has, or
     * when neither side has any data. A side without data is null.
     *
     * @return array{local: StartTeamBlock|null, guest: StartTeamBlock|null}|null
     */
    public function forFixture(Fixture $fixture): ?array
    {
        if ($fixture->state !== FixtureState::Scheduled) {
            return null;
        }

        $fixture->loadMissing(['localTeam', 'guestTeam']);

        $local = $this->teamBlock($fixture, $fixture->localTeam);
        $guest = $this->teamBlock($fixture, $fixture->guestTeam);

        return $local === null && $guest === null ? null : ['local' => $local, 'guest' => $guest];
    }

    /**
     * The team's block for its next match — the "próximo partido" the fichas
     * show — or null when that match has no data yet.
     *
     * @return array{fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null, is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, players: list<StartEntry>, opponent: Team, is_home: bool}|null
     */
    public function forTeamNextFixture(Team $team, Season $season): ?array
    {
        $fixture = $this->nextFixtures($season, [$team->id])[$team->id] ?? null;

        if ($fixture === null) {
            return null;
        }

        $block = $this->teamBlock($fixture, $team);

        if ($block === null) {
            return null;
        }

        $isHome = $fixture->team_local_id === $team->id;

        return [
            ...$block,
            'opponent' => $isHome ? $fixture->guestTeam : $fixture->localTeam,
            'is_home' => $isHome,
        ];
    }

    /**
     * Each player's start for his team's next match, keyed by player id.
     * Out-of-league players, and players without a row or a confirmed
     * lineup for that match, are absent. Expects `team` to be loaded.
     *
     * @param  Collection<int, Player>  $players
     * @return array<int, PlayerNextStart>
     */
    public function forPlayersNextFixture(Collection $players, Season $season): array
    {
        $eligible = $players->filter(fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague);
        $teamIds = array_values(array_unique($eligible->map(fn (Player $player): int => $player->team_id)->all()));
        $nextByTeam = $this->nextFixtures($season, $teamIds);

        if ($nextByTeam === []) {
            return [];
        }

        $fixtureIds = array_values(array_map(fn (Fixture $fixture): int => $fixture->id, $nextByTeam));

        $rows = FixtureLineupProbability::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereIn('player_id', $eligible->map(fn (Player $player): int => $player->id)->all())
            ->get()
            ->keyBy(fn (FixtureLineupProbability $row): string => "{$row->fixture_id}:{$row->player_id}");

        $lineups = FixtureLineup::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereNotNull('player_id')
            ->get(['fixture_id', 'team_id', 'player_id', 'starter']);

        $confirmedTeams = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => ["{$lineup->fixture_id}:{$lineup->team_id}" => true]);
        $lineupStarters = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => ["{$lineup->fixture_id}:{$lineup->player_id}" => $lineup->starter]);

        $nextStarts = [];

        foreach ($eligible as $player) {
            $fixture = $nextByTeam[$player->team_id] ?? null;

            if ($fixture === null) {
                continue;
            }

            $row = $rows->get("{$fixture->id}:{$player->id}");
            $byWorldcup26 = $confirmedTeams->has("{$fixture->id}:{$player->team_id}");

            if ($row === null && !$byWorldcup26) {
                continue;
            }

            $confirmedSource = match (true) {
                $byWorldcup26 => 'worldcup26',
                $row->confirmed_starter !== null => 'futbolfantasy',
                default => null,
            };

            $nextStarts[$player->id] = [
                'fixture_id' => $fixture->id,
                'week_number' => $fixture->week_number,
                'probability' => $row?->probability,
                'predicted_starter' => $row !== null && $row->predicted_starter,
                'confirmed_starter' => match ($confirmedSource) {
                    'worldcup26' => (bool) $lineupStarters->get("{$fixture->id}:{$player->id}", false),
                    'futbolfantasy' => $row->confirmed_starter,
                    default => null,
                },
                'confirmed_source' => $confirmedSource,
                'is_stale' => $confirmedSource !== 'worldcup26' && $row !== null && $this->isStale($row->fetched_at),
                'fetched_at' => $row?->fetched_at->toIso8601String(),
                'source_url' => FutbolFantasyTeams::pageUrlFor($player->team->fantasy_id) ?? '',
                'team_short_name' => $player->team->short_name,
            ];
        }

        return $nextStarts;
    }

    /**
     * @return StartTeamBlock|null
     */
    private function teamBlock(Fixture $fixture, Team $team): ?array
    {
        $rows = FixtureLineupProbability::query()
            ->where('fixture_id', $fixture->id)
            ->whereHas('player', fn ($query) => $query->where('team_id', $team->id))
            ->with('player.team')
            ->get();

        $lineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->where('team_id', $team->id)
            ->whereNotNull('player_id')
            ->with('player.team')
            ->get();

        if ($rows->isEmpty() && $lineups->isEmpty()) {
            return null;
        }

        $confirmedSource = match (true) {
            $lineups->isNotEmpty() => 'worldcup26',
            $rows->contains(fn (FixtureLineupProbability $row): bool => $row->confirmed_starter !== null) => 'futbolfantasy',
            default => null,
        };

        $startersByPlayer = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => [(int) $lineup->player_id => $lineup->starter]);

        /** @var list<StartEntry> $players */
        $players = [];

        foreach ($rows as $row) {
            $players[] = [
                'player' => $row->player,
                'probability' => $row->probability,
                'predicted_starter' => $row->predicted_starter,
                'confirmed_starter' => match ($confirmedSource) {
                    'worldcup26' => (bool) $startersByPlayer->get($row->player_id, false),
                    'futbolfantasy' => $row->confirmed_starter ?? false,
                    default => null,
                },
            ];
        }

        $listed = $rows->pluck('player_id')->flip();

        foreach ($lineups as $lineup) {
            if (!$lineup->player instanceof Player || $listed->has($lineup->player_id)) {
                continue;
            }

            $players[] = [
                'player' => $lineup->player,
                'probability' => null,
                'predicted_starter' => false,
                'confirmed_starter' => $lineup->starter,
            ];
        }

        $this->attachCurrentSeason(collect(array_map(fn (array $entry): Player => $entry['player'], $players)), $fixture->season_id);

        $fetchedAt = $rows->sortByDesc(fn (FixtureLineupProbability $row): int => $row->fetched_at->getTimestamp())->first()?->fetched_at;

        return [
            'fixture_id' => $fixture->id,
            'week_number' => $fixture->week_number,
            'team' => $team,
            'source_url' => FutbolFantasyTeams::pageUrlFor($team->fantasy_id) ?? '',
            'fetched_at' => $fetchedAt?->toIso8601String(),
            'is_stale' => $confirmedSource !== 'worldcup26' && $fetchedAt !== null && $this->isStale($fetchedAt),
            'confirmed_source' => $confirmedSource,
            'players' => $players,
        ];
    }

    /**
     * Each team's next match: the soonest `Scheduled` fixture by date — the
     * same "próximo partido" the team ficha and the roster show.
     *
     * @param  list<int>  $teamIds
     * @return array<int, Fixture>
     */
    private function nextFixtures(Season $season, array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $nextByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds))
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get()
            ->each(function (Fixture $fixture) use ($teamIds, &$nextByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true) && !isset($nextByTeam[$teamId])) {
                        $nextByTeam[$teamId] = $fixture;
                    }
                }
            });

        return $nextByTeam;
    }

    private function isStale(CarbonImmutable $fetchedAt): bool
    {
        return $fetchedAt->lessThan(now()->subHours(self::STALE_AFTER_HOURS));
    }
}
