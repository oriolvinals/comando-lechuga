<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\FixtureLineupProbabilityAlternative;
use App\Models\ManagerLineupPlayer;
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
 * Each starter also gets a `pitch_position` in worldcup26's vocabulary
 * ("Right Back", "Center Left Midfielder"…) and each side a `formation`, so
 * the XI draws like a confirmed lineup: worldcup26's own once it has
 * confirmed the lineup, otherwise read off where FF draws its probable XI
 * (PredictedFormation — a heuristic, hence null when FF drew nothing).
 *
 * @phpstan-type StartAlternative array{position: int, name: string, player: Player|null}
 * @phpstan-type StartEntry array{player: Player, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, pitch_position: string|null, alternatives: list<StartAlternative>}
 * @phpstan-type StartTeamBlock array{fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null, is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, formation: string|null, players: list<StartEntry>}
 * @phpstan-type PlayerNextStart array{fixture_id: int, week_number: int, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, confirmed_source: 'worldcup26'|'futbolfantasy'|null, is_stale: bool, fetched_at: string|null, source_url: string, team_short_name: string, opponent: Team, is_home: bool, date: string}
 * @phpstan-type LineupEntryStart array{probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, is_stale: bool, fetched_at: string|null}
 */
class StartProbabilities
{
    use AttachesCurrentPlayerSeason;

    /** Data older than this is shown as stale ("Datos de hace N días"). */
    public const int STALE_AFTER_HOURS = 48;

    public function __construct(private readonly PredictedFormation $predictedFormation) {}

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
     * @return array{fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null, is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, formation: string|null, players: list<StartEntry>, opponent: Team, is_home: bool}|null
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
     * With `$fromWeek`, the next match is the first one of that jornada or
     * a later one (the comparator skips a pending match of a live jornada).
     *
     * @param  Collection<int, Player>  $players
     * @return array<int, PlayerNextStart>
     */
    public function forPlayersNextFixture(Collection $players, Season $season, ?int $fromWeek = null): array
    {
        $eligible = $players->filter(fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague);
        $teamIds = array_values(array_unique($eligible->map(fn (Player $player): int => $player->team_id)->all()));
        $nextByTeam = $this->nextFixtures($season, $teamIds, $fromWeek);

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

        $confirmedTeams = $lineups
            ->filter(fn (FixtureLineup $lineup): bool => $lineup->starter)
            ->mapWithKeys(fn (FixtureLineup $lineup): array => ["{$lineup->fixture_id}:{$lineup->team_id}" => true]);
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

            $isHome = $fixture->team_local_id === $player->team_id;

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
                'opponent' => $isHome ? $fixture->guestTeam : $fixture->localTeam,
                'is_home' => $isHome,
                'date' => $fixture->date->toIso8601String(),
            ];
        }

        return $nextStarts;
    }

    /**
     * Each lineup entry's start facts for its OWN fixture — the jornada being
     * viewed, not necessarily the team's next match — keyed by
     * `ManagerLineupPlayer::$id`. Absent once that fixture has kicked off (or
     * has none resolved), or when neither FútbolFantasy nor worldcup26 have
     * anything for that player. Expects every entry's `fixture` (and
     * `player.team`) to already be resolved — see AttachesLineupFixtures.
     *
     * @param  Collection<int, ManagerLineupPlayer>  $entries
     * @return array<int, LineupEntryStart>
     */
    public function forLineupEntries(Collection $entries): array
    {
        $eligible = $entries->filter(fn (ManagerLineupPlayer $entry): bool => $entry->fixture instanceof Fixture && $entry->fixture->state === FixtureState::Scheduled);

        if ($eligible->isEmpty()) {
            return [];
        }

        $fixtureIds = $eligible->map(fn (ManagerLineupPlayer $entry): int => $entry->fixture->id)->unique()->values()->all();
        $playerIds = $eligible->pluck('player_id')->unique()->values()->all();

        $rows = FixtureLineupProbability::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereIn('player_id', $playerIds)
            ->get()
            ->keyBy(fn (FixtureLineupProbability $row): string => "{$row->fixture_id}:{$row->player_id}");

        $lineups = FixtureLineup::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereNotNull('player_id')
            ->get(['fixture_id', 'team_id', 'player_id', 'starter']);

        $confirmedTeams = $lineups
            ->filter(fn (FixtureLineup $lineup): bool => $lineup->starter)
            ->mapWithKeys(fn (FixtureLineup $lineup): array => ["{$lineup->fixture_id}:{$lineup->team_id}" => true]);
        $lineupStarters = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => ["{$lineup->fixture_id}:{$lineup->player_id}" => $lineup->starter]);

        $starts = [];

        foreach ($eligible as $entry) {
            $fixture = $entry->fixture;
            $row = $rows->get("{$fixture->id}:{$entry->player_id}");
            $byWorldcup26 = $confirmedTeams->has("{$fixture->id}:{$entry->player->team_id}");

            if ($row === null && !$byWorldcup26) {
                continue;
            }

            $confirmedSource = match (true) {
                $byWorldcup26 => 'worldcup26',
                $row->confirmed_starter !== null => 'futbolfantasy',
                default => null,
            };

            $starts[$entry->id] = [
                'probability' => $row?->probability,
                'predicted_starter' => $row !== null && $row->predicted_starter,
                'confirmed_starter' => match ($confirmedSource) {
                    'worldcup26' => (bool) $lineupStarters->get("{$fixture->id}:{$entry->player_id}", false),
                    'futbolfantasy' => $row->confirmed_starter,
                    default => null,
                },
                'is_stale' => $confirmedSource !== 'worldcup26' && $row !== null && $this->isStale($row->fetched_at),
                'fetched_at' => $row?->fetched_at->toIso8601String(),
            ];
        }

        return $starts;
    }

    /**
     * @return StartTeamBlock|null
     */
    private function teamBlock(Fixture $fixture, Team $team): ?array
    {
        $rows = FixtureLineupProbability::query()
            ->where('fixture_id', $fixture->id)
            ->whereHas('player', fn ($query) => $query->where('team_id', $team->id))
            ->with(['player.team', 'alternatives.player.team'])
            ->get();

        $lineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->where('team_id', $team->id)
            ->whereNotNull('player_id')
            ->with('player.team')
            ->get();

        $block = $this->buildBlock($fixture, $team, $rows, $lineups);

        if ($block !== null) {
            $this->attachCurrentSeason(collect($this->blockPlayers($block)), $fixture->season_id);
        }

        return $block;
    }

    /**
     * Every team's block for its own next match, in a fixed number of
     * queries whatever the number of teams — the same rules as
     * {@see teamBlock()}, batched for pages that need every team's block at
     * once (the `/api/teams` table).
     *
     * @param  array<int, Fixture>  $nextFixtureByTeam  keyed by team id, one entry per team with a next match — the same "next match" rule as {@see nextFixtures()}, with `localTeam`/`guestTeam` loaded
     * @return array<int, array{fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null, is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, formation: string|null, players: list<StartEntry>, opponent: Team, is_home: bool}|null>
     */
    public function forTeamsNextFixtures(Season $season, array $nextFixtureByTeam): array
    {
        if ($nextFixtureByTeam === []) {
            return [];
        }

        $fixtureIds = array_values(array_unique(array_map(fn (Fixture $fixture): int => $fixture->id, $nextFixtureByTeam)));

        $rowsByFixtureAndTeam = FixtureLineupProbability::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->with(['player.team', 'alternatives.player.team'])
            ->get()
            ->groupBy(fn (FixtureLineupProbability $row): string => "{$row->fixture_id}:{$row->player->team_id}");

        $lineupsByFixtureAndTeam = FixtureLineup::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereNotNull('player_id')
            ->with('player.team')
            ->get()
            ->groupBy(fn (FixtureLineup $lineup): string => "{$lineup->fixture_id}:{$lineup->team_id}");

        $blocks = [];
        $allPlayers = collect();

        foreach ($nextFixtureByTeam as $teamId => $fixture) {
            $isHome = $fixture->team_local_id === $teamId;
            $team = $isHome ? $fixture->localTeam : $fixture->guestTeam;
            $rows = $rowsByFixtureAndTeam->get("{$fixture->id}:{$teamId}", collect());
            $lineups = $lineupsByFixtureAndTeam->get("{$fixture->id}:{$teamId}", collect());
            $block = $this->buildBlock($fixture, $team, $rows, $lineups);

            if ($block === null) {
                $blocks[$teamId] = null;

                continue;
            }

            $blocks[$teamId] = [
                ...$block,
                'opponent' => $isHome ? $fixture->guestTeam : $fixture->localTeam,
                'is_home' => $isHome,
            ];

            $allPlayers->push(...$this->blockPlayers($block));
        }

        if ($allPlayers->isNotEmpty()) {
            $this->attachCurrentSeason($allPlayers, $season->id);
        }

        return $blocks;
    }

    /**
     * The side's block built from already-loaded rows/lineups — the rules
     * shared by the single-team lookup ({@see teamBlock()}) and the batched
     * one ({@see forTeamsNextFixtures()}). Does not attach current-season
     * figures to the players it returns; callers batch that themselves.
     *
     * @param  Collection<int, FixtureLineupProbability>  $rows
     * @param  Collection<int, FixtureLineup>  $lineups
     * @return StartTeamBlock|null
     */
    private function buildBlock(Fixture $fixture, Team $team, Collection $rows, Collection $lineups): ?array
    {
        if ($rows->isEmpty() && $lineups->isEmpty()) {
            return null;
        }

        $confirmedSource = match (true) {
            $lineups->contains(fn (FixtureLineup $lineup): bool => $lineup->starter) => 'worldcup26',
            $rows->contains(fn (FixtureLineupProbability $row): bool => $row->confirmed_starter !== null) => 'futbolfantasy',
            default => null,
        };

        $startersByPlayer = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => [(int) $lineup->player_id => $lineup->starter]);
        ['formation' => $formation, 'positions' => $pitchPositions] = $this->shape($fixture, $team, $rows, $lineups, $confirmedSource);

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
                'pitch_position' => $pitchPositions[$row->player_id] ?? null,
                'alternatives' => $confirmedSource === null ? $this->alternatives($row) : [],
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
                'pitch_position' => $pitchPositions[(int) $lineup->player_id] ?? null,
                'alternatives' => [],
            ];
        }

        $fetchedAt = $rows->sortByDesc(fn (FixtureLineupProbability $row): int => $row->fetched_at->getTimestamp())->first()?->fetched_at;

        return [
            'fixture_id' => $fixture->id,
            'week_number' => $fixture->week_number,
            'team' => $team,
            'source_url' => FutbolFantasyTeams::pageUrlFor($team->fantasy_id) ?? '',
            'fetched_at' => $fetchedAt?->toIso8601String(),
            'is_stale' => $confirmedSource !== 'worldcup26' && $fetchedAt !== null && $this->isStale($fetchedAt),
            'confirmed_source' => $confirmedSource,
            'formation' => $formation,
            'players' => $players,
        ];
    }

    /**
     * The players FF lists under him as the ones who could start instead,
     * in FF's order: our player when linked, else only FF's name. Only while
     * the lineup is predicted — a confirmed one has no alternatives.
     *
     * @return list<StartAlternative>
     */
    private function alternatives(FixtureLineupProbability $row): array
    {
        return array_values($row->alternatives
            ->map(fn (FixtureLineupProbabilityAlternative $alternative): array => [
                'position' => $alternative->position,
                'name' => $alternative->name,
                'player' => $alternative->player,
            ])
            ->all());
    }

    /**
     * The players of the block and of their alternatives, for the
     * current-season figures the fichas show on both.
     *
     * @param  StartTeamBlock  $block
     * @return list<Player>
     */
    private function blockPlayers(array $block): array
    {
        $players = [];

        foreach ($block['players'] as $entry) {
            $players[] = $entry['player'];

            foreach ($entry['alternatives'] as $alternative) {
                if ($alternative['player'] instanceof Player) {
                    $players[] = $alternative['player'];
                }
            }
        }

        return $players;
    }

    /**
     * The side's formation and each starter's worldcup26-style position:
     * worldcup26's own once it has confirmed the lineup, else read off FF's
     * probable XI. FF's "Alineación confirmada" keeps the probable XI's
     * shape — its surprise starters have no spot and get no position.
     *
     * @param  Collection<int, FixtureLineupProbability>  $rows
     * @param  Collection<int, FixtureLineup>  $lineups
     * @param  'worldcup26'|'futbolfantasy'|null  $confirmedSource
     * @return array{formation: string|null, positions: array<int, string>}
     */
    private function shape(Fixture $fixture, Team $team, Collection $rows, Collection $lineups, ?string $confirmedSource): array
    {
        if ($confirmedSource === 'worldcup26') {
            return [
                'formation' => $fixture->team_local_id === $team->id ? $fixture->local_formation : $fixture->guest_formation,
                'positions' => $lineups
                    ->filter(fn (FixtureLineup $lineup): bool => $lineup->starter && $lineup->position !== '')
                    ->mapWithKeys(fn (FixtureLineup $lineup): array => [(int) $lineup->player_id => $lineup->position])
                    ->all(),
            ];
        }

        $spots = $rows
            ->filter(fn (FixtureLineupProbability $row): bool => $row->predicted_starter && $row->pitch_x !== null && $row->pitch_y !== null)
            ->mapWithKeys(fn (FixtureLineupProbability $row): array => [$row->player_id => ['x' => (int) $row->pitch_x, 'y' => (int) $row->pitch_y]])
            ->all();

        return $this->predictedFormation->derive($spots);
    }

    /**
     * Each team's next match: the soonest `Scheduled` fixture with a future
     * date — the same "próximo partido" the team ficha and the roster show,
     * and the same `date > now()` guard as the sync command's `nextFixture`,
     * so an overdue Scheduled fixture (a postponement, for example) doesn't
     * keep showing stale probabilities. Public so `MatchDifficulty` finds the
     * same next match its absence adjustment reads the probable XI for.
     *
     * @param  list<int>  $teamIds
     * @param  int|null  $fromWeek  only fixtures of this jornada or a later one
     * @return array<int, Fixture> keyed by team id, with `localTeam`/`guestTeam` loaded
     */
    public function nextFixtures(Season $season, array $teamIds, ?int $fromWeek = null): array
    {
        if ($teamIds === []) {
            return [];
        }

        $nextByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', now())
            ->when($fromWeek !== null, fn ($query) => $query->where('week_number', '>=', $fromWeek))
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
