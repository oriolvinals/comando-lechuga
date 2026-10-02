<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FantasyStat;
use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The season's player rankings by LaLiga Fantasy action, summed from every
 * match's `fixture_lineups.fantasy_stats` (finished and live alike). For one
 * manager, only the matches of the jornadas he lined the player up count.
 * The sums are cached for a minute: the live sync rewrites a match's stats
 * every minute anyway.
 *
 * A ranking holds the players with a total above zero, ordered by total,
 * then by fewer matches played, then by name, and ranked competition-style
 * (1, 1, 3).
 *
 * @phpstan-type StatTotals array{matches: int, totals: array<string, int>}
 * @phpstan-type RankedPlayer array{rank: int, value: int, matches: int, player: Player}
 * @phpstan-type LineupManager array{id: int, name: string, primary_color: string|null}
 * @phpstan-type MatchValue array{fixture_id: int, week_number: int, rival: Team, is_home: bool, own_score: int|null, rival_score: int|null, value: int, minutes: int, played: bool, lined_up_by: list<LineupManager>}
 */
class PlayerStatRankings
{
    private const int CACHE_SECONDS = 60;

    /**
     * Each player's matches played (any minute on the pitch) and total of
     * every action, over the season or one jornada ($week) — only the
     * matches $managerId lined him up for, when given.
     *
     * @return array<int, StatTotals> keyed by player id
     */
    public function totals(Season $season, ?int $managerId = null, ?int $week = null): array
    {
        return Cache::remember("player-stat-totals:{$season->id}:".($managerId ?? 'all').':'.($week ?? 'all'), self::CACHE_SECONDS, function () use ($season, $managerId, $week): array {
            $linedUp = $managerId === null ? null : $this->linedUpWeeks($season, $managerId);
            $totals = [];

            FixtureLineup::query()
                ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
                ->where('fixtures.season_id', $season->id)
                ->whereNotNull('fixture_lineups.player_id')
                ->whereNotNull('fixture_lineups.fantasy_stats')
                ->when($week !== null, fn ($query) => $query->where('fixtures.week_number', $week))
                ->select(['fixture_lineups.id', 'fixture_lineups.player_id', 'fixture_lineups.fantasy_stats', 'fixtures.week_number', 'fixtures.dazn_published'])
                ->lazyById(1000, 'fixture_lineups.id', 'id')
                ->each(function (FixtureLineup $lineup) use (&$totals, $linedUp): void {
                    $playerId = (int) $lineup->player_id;
                    $week = (int) $lineup->getAttribute('week_number');

                    if ($linedUp !== null && !isset($linedUp[$playerId][$week])) {
                        return;
                    }

                    $totals[$playerId] ??= ['matches' => 0, 'totals' => array_fill_keys(array_column(FantasyStat::cases(), 'value'), 0)];

                    foreach (FantasyStat::cases() as $stat) {
                        $totals[$playerId]['totals'][$stat->value] += self::value($lineup->fantasy_stats, $stat, (bool) $lineup->getAttribute('dazn_published'));
                    }

                    if (self::value($lineup->fantasy_stats, FantasyStat::MinsPlayed) > 0) {
                        $totals[$playerId]['matches']++;
                    }
                });

            return $totals;
        });
    }

    /**
     * The players the manager lined up at least once this season.
     *
     * @return list<int>
     */
    public function linedUpPlayerIds(Season $season, int $managerId): array
    {
        return array_keys($this->linedUpWeeks($season, $managerId));
    }

    /**
     * The players of $players ranked by one action.
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, StatTotals>  $totals
     * @return list<RankedPlayer>
     */
    public function rank(Collection $players, array $totals, FantasyStat $stat): array
    {
        $rows = $players
            ->map(fn (Player $player): array => [
                'player' => $player,
                'value' => $totals[$player->id]['totals'][$stat->value] ?? 0,
                'matches' => $totals[$player->id]['matches'] ?? 0,
            ])
            ->filter(fn (array $row): bool => $row['value'] > 0)
            ->sort(fn (array $a, array $b): int => [$b['value'], $a['matches'], $a['player']->nickname] <=> [$a['value'], $b['matches'], $b['player']->nickname])
            ->values();

        $ranked = [];
        $rank = 0;
        $previous = null;

        foreach ($rows as $index => $row) {
            if ($row['value'] !== $previous) {
                $rank = $index + 1;
                $previous = $row['value'];
            }

            $ranked[] = ['rank' => $rank, ...$row];
        }

        return $ranked;
    }

    /**
     * The managers who lined each player up this season, or in one jornada
     * ($week), in name order.
     *
     * @param  list<int>  $playerIds
     * @return array<int, list<LineupManager>> keyed by player id
     */
    public function linedUpBy(Season $season, array $playerIds, ?int $week = null): array
    {
        $byPlayer = [];

        foreach ($this->seasonLineups($season, $playerIds, week: $week) as $row) {
            $byPlayer[(int) $row->player_id][(int) $row->season_manager_id] = true;
        }

        $managers = $this->managers($season);

        return array_map(
            fn (array $managerIds): array => array_values(collect(array_keys($managerIds))
                ->map(fn (int $id): ?array => $managers[$id] ?? null)
                ->filter()
                ->sortBy('name')
                ->all()),
            $byPlayer,
        );
    }

    /**
     * The player's value of one action in each match of the season: every
     * match he has a lineup row for, plus his current team's other started
     * matches as «No jugó», in jornada order, each with the managers who
     * lined him up that jornada.
     *
     * @return list<MatchValue>
     */
    public function matches(Player $player, Season $season, FantasyStat $stat): array
    {
        $lineups = FixtureLineup::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', Fixture::query()->where('season_id', $season->id)->select('id'))
            ->get(['fixture_id', 'team_id', 'fantasy_stats'])
            ->keyBy('fixture_id');

        $managers = $this->managers($season);

        /** @var array<int, list<int>> $managersByWeek */
        $managersByWeek = [];

        foreach ($this->seasonLineups($season, [$player->id]) as $row) {
            $managersByWeek[(int) $row->week_number][] = (int) $row->season_manager_id;
        }

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where(fn ($query) => $query
                ->whereIn('id', $lineups->keys())
                ->orWhere(fn ($query) => $query
                    ->whereNotIn('state', [FixtureState::Scheduled, FixtureState::Postponed])
                    ->where(fn ($query) => $query
                        ->where('team_local_id', $player->team_id)
                        ->orWhere('team_guest_id', $player->team_id))))
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('week_number')
            ->orderBy('date')
            ->get();

        return array_values($fixtures
            ->filter(fn (Fixture $fixture): bool => $fixture->localTeam instanceof Team && $fixture->guestTeam instanceof Team)
            ->map(function (Fixture $fixture) use ($lineups, $player, $stat, $managers, $managersByWeek): array {
                $lineup = $lineups->get($fixture->id);
                $teamId = $lineup->team_id ?? $player->team_id;
                $isHome = $fixture->team_local_id === $teamId;
                $minutes = self::value($lineup?->fantasy_stats, FantasyStat::MinsPlayed);

                return [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'rival' => $isHome ? $fixture->guestTeam : $fixture->localTeam,
                    'is_home' => $isHome,
                    'own_score' => $isHome ? $fixture->local_score : $fixture->guest_score,
                    'rival_score' => $isHome ? $fixture->guest_score : $fixture->local_score,
                    'value' => self::value($lineup?->fantasy_stats, $stat, $fixture->dazn_published),
                    'minutes' => $minutes,
                    'played' => $minutes > 0,
                    'lined_up_by' => array_values(array_filter(array_map(
                        fn (int $id): ?array => $managers[$id] ?? null,
                        array_unique($managersByWeek[$fixture->week_number] ?? []),
                    ))),
                ];
            })
            ->all());
    }

    /**
     * For the manager, the jornadas each player was in his lineup.
     *
     * @return array<int, array<int, true>> player id => week number => true
     */
    private function linedUpWeeks(Season $season, int $managerId): array
    {
        $weeks = [];

        foreach ($this->seasonLineups($season, null, $managerId) as $row) {
            $weeks[(int) $row->player_id][(int) $row->week_number] = true;
        }

        return $weeks;
    }

    /**
     * Every lined-up player of the season's managers (of one jornada, when
     * given), with the jornada and the manager.
     *
     * @param  list<int>|null  $playerIds
     * @return Collection<int, \stdClass>
     */
    private function seasonLineups(Season $season, ?array $playerIds = null, ?int $managerId = null, ?int $week = null): Collection
    {
        return ManagerLineupPlayer::query()
            ->toBase()
            ->join('manager_lineups', 'manager_lineups.id', '=', 'manager_lineup_players.manager_lineup_id')
            ->join('season_managers', 'season_managers.id', '=', 'manager_lineups.season_manager_id')
            ->where('season_managers.season_id', $season->id)
            ->when($playerIds !== null, fn ($query) => $query->whereIn('manager_lineup_players.player_id', $playerIds))
            ->when($managerId !== null, fn ($query) => $query->where('manager_lineups.season_manager_id', $managerId))
            ->when($week !== null, fn ($query) => $query->where('manager_lineups.week_number', $week))
            ->get(['manager_lineup_players.player_id', 'manager_lineups.week_number', 'manager_lineups.season_manager_id']);
    }

    /**
     * @return array<int, LineupManager> keyed by id
     */
    private function managers(Season $season): array
    {
        return SeasonManager::query()
            ->where('season_id', $season->id)
            ->get(['id', 'name', 'primary_color'])
            ->mapWithKeys(fn (SeasonManager $manager): array => [$manager->id => [
                'id' => $manager->id,
                'name' => $manager->name,
                'primary_color' => $manager->primary_color,
            ]])
            ->all();
    }

    /**
     * A lineup's value of the action: the pair's value, except DAZN's
     * rating, whose official points count only once the fixture is
     * published (as DaznEstimatePresenter shows them).
     *
     * @param  array<string, mixed>|null  $stats  a lineup's `fantasy_stats`
     */
    private static function value(?array $stats, FantasyStat $stat, bool $daznPublished = false): int
    {
        $pair = $stats[$stat->value] ?? null;
        $side = $stat === FantasyStat::DaznPoints ? 1 : 0;

        if ($stat === FantasyStat::DaznPoints && !$daznPublished) {
            return 0;
        }

        return is_array($pair) && is_numeric($pair[$side] ?? null) ? (int) $pair[$side] : 0;
    }
}
