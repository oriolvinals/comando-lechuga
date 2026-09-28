<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\SeasonClock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * A hidden, undocumented endpoint: a jornada-by-jornada dump of the whole
 * season, built so an external AI can turn it into an end-of-season recap
 * video. Deliberately left out of resources/docs/api-docs.md and excluded
 * from ApiDocsDriftTest's "every route is documented" check — see that
 * test for why.
 *
 * Only finished jornadas (`SeasonClock::finishedWeekNumbers`) are included.
 * Each manager's `points`/`total_points` come straight from `ManagerLineup`,
 * never resummed from individual picks. `rank` is competition ranking on the
 * cumulative total (ties share the better place).
 *
 * `star_players` nests under the manager who actually fielded that pick that
 * week — a player who scored 8+ points while benched everywhere, or unowned,
 * never appears at all. Its `team` is the team he played FOR IN THAT MATCH
 * (the linked `fixture_lineups` row's `team_id`), never his current club —
 * the same historical-team invariant the player ficha's own score history
 * relies on. Because a `ManagerLineupPlayer` already resolves to a single
 * `fixture_id` for its week (see that model's docblock), a double-jornada
 * never produces two scores for the same pick here.
 */
class TimelineController extends Controller
{
    private const string LEAGUE_NAME = 'Comando Lechuga';

    private const int STAR_THRESHOLD = 8;

    public function index(SeasonClock $clock): JsonResponse
    {
        $season = Season::current();

        $managers = SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('id')
            ->get();

        $finishedWeeks = $clock->finishedWeekNumbers($season);

        $lineups = ManagerLineup::query()
            ->whereIn('season_manager_id', $managers->pluck('id'))
            ->whereIn('week_number', $finishedWeeks)
            ->get();

        $entries = ManagerLineupPlayer::query()
            ->whereIn('manager_lineup_id', $lineups->pluck('id'))
            ->whereNotNull('fixture_id')
            ->get(['id', 'manager_lineup_id', 'player_id', 'fixture_id']);

        $starLineupsByEntry = $this->starLineupsByFixtureAndPlayer($entries);
        $entriesByLineupId = $this->groupEntriesByLineupId($entries);
        $lineupsByWeekAndManager = $this->lineupsByWeekAndManager($lineups);

        $weeks = $this->buildWeeks($managers, $finishedWeeks, $lineupsByWeekAndManager, $entriesByLineupId, $starLineupsByEntry);

        return response()->json(['data' => [
            'league' => [
                'name' => self::LEAGUE_NAME,
                'logo' => asset('images/logo.png'),
                'season' => $season->name,
                'total_weeks' => $season->total_weeks,
            ],
            'managers' => $managers->map(fn (SeasonManager $manager): array => [
                'id' => $manager->id,
                'name' => $manager->name,
                'logo' => $manager->logo ? asset($manager->logo) : '',
            ])->values()->all(),
            'weeks' => $weeks,
        ]]);
    }

    /**
     * `manager_lineups` keyed first by `week_number`, then by
     * `season_manager_id` — a plain array rather than a Collection-of-
     * -Collections, since Eloquent Collection's generics can't express an
     * Eloquent Collection nested inside another one.
     *
     * @param  Collection<int, ManagerLineup>  $lineups
     * @return array<int, array<int, ManagerLineup>>
     */
    private function lineupsByWeekAndManager(Collection $lineups): array
    {
        $byWeekAndManager = [];

        foreach ($lineups as $lineup) {
            $byWeekAndManager[$lineup->week_number][$lineup->season_manager_id] = $lineup;
        }

        return $byWeekAndManager;
    }

    /**
     * `manager_lineup_players` grouped by `manager_lineup_id`, for the same
     * generics reason as {@see lineupsByWeekAndManager()}.
     *
     * @param  Collection<int, ManagerLineupPlayer>  $entries
     * @return array<int, list<ManagerLineupPlayer>>
     */
    private function groupEntriesByLineupId(Collection $entries): array
    {
        $byLineupId = [];

        foreach ($entries as $entry) {
            $byLineupId[$entry->manager_lineup_id][] = $entry;
        }

        return $byLineupId;
    }

    /**
     * Every finished jornada in ascending order, each with every manager's
     * points, cumulative total, rank, and the stars that manager fielded.
     *
     * @param  Collection<int, SeasonManager>  $managers
     * @param  list<int>  $finishedWeeks
     * @param  array<int, array<int, ManagerLineup>>  $lineupsByWeekAndManager
     * @param  array<int, list<ManagerLineupPlayer>>  $entriesByLineupId
     * @param  array<string, FixtureLineup>  $starLineupsByEntry
     * @return list<array<string, mixed>>
     */
    private function buildWeeks(
        Collection $managers,
        array $finishedWeeks,
        array $lineupsByWeekAndManager,
        array $entriesByLineupId,
        array $starLineupsByEntry,
    ): array {
        $cumulative = $managers->mapWithKeys(fn (SeasonManager $manager): array => [$manager->id => 0])->all();
        $weeks = [];

        foreach ($finishedWeeks as $weekNumber) {
            $weekLineups = $lineupsByWeekAndManager[$weekNumber] ?? [];

            $points = [];

            foreach ($managers as $manager) {
                $points[$manager->id] = $weekLineups[$manager->id]->points ?? 0;
                $cumulative[$manager->id] += $points[$manager->id];
            }

            $ranks = $this->ranksByManagerId($managers, $cumulative);

            $weekManagers = $managers
                ->map(function (SeasonManager $manager) use ($weekLineups, $points, $cumulative, $ranks, $entriesByLineupId, $starLineupsByEntry): array {
                    $lineup = $weekLineups[$manager->id] ?? null;

                    return [
                        'manager_id' => $manager->id,
                        'points' => $points[$manager->id],
                        'total_points' => $cumulative[$manager->id],
                        'rank' => $ranks[$manager->id],
                        'star_players' => $lineup === null
                            ? []
                            : $this->starPlayersForLineup($lineup, $entriesByLineupId, $starLineupsByEntry),
                    ];
                })
                ->sortBy('rank')
                ->values()
                ->all();

            $weeks[] = [
                'week_number' => $weekNumber,
                'managers' => $weekManagers,
            ];
        }

        return $weeks;
    }

    /**
     * This manager's stars for one jornada: the players he fielded (via his
     * `ManagerLineup`'s entries) who scored 8+ points in the match their
     * `fixture_id` resolves to, sorted by points descending.
     *
     * @param  array<int, list<ManagerLineupPlayer>>  $entriesByLineupId
     * @param  array<string, FixtureLineup>  $starLineupsByEntry
     * @return list<array<string, mixed>>
     */
    private function starPlayersForLineup(ManagerLineup $lineup, array $entriesByLineupId, array $starLineupsByEntry): array
    {
        $entries = $entriesByLineupId[$lineup->id] ?? [];

        $starLineups = collect($entries)
            ->map(fn (ManagerLineupPlayer $entry): ?FixtureLineup => $starLineupsByEntry["{$entry->fixture_id}-{$entry->player_id}"] ?? null)
            ->filter()
            ->sortByDesc('fantasy_points');

        $starPlayers = [];

        foreach ($starLineups as $starLineup) {
            $starPlayers[] = [
                'id' => $starLineup->player_id,
                'name' => $starLineup->player->nickname,
                'image' => $starLineup->player->image ? asset('storage/'.$starLineup->player->image) : '',
                'points' => $starLineup->fantasy_points,
                'team' => (new TeamResource($starLineup->team))->resolve(),
            ];
        }

        return $starPlayers;
    }

    /**
     * Bulk-fetches, in one query (plus its `team`/`player` eager loads), every
     * `fixture_lineups` row that could be a star: one of this batch of lineup
     * entries' (fixture_id, player_id) pairs, scoring 8+ points. Keyed the
     * same way so a specific entry's star (if any) is a single array lookup.
     *
     * @param  Collection<int, ManagerLineupPlayer>  $entries
     * @return array<string, FixtureLineup>
     */
    private function starLineupsByFixtureAndPlayer(Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return [];
        }

        return FixtureLineup::query()
            ->whereIn('fixture_id', $entries->pluck('fixture_id')->unique())
            ->whereIn('player_id', $entries->pluck('player_id')->unique())
            ->where('fantasy_points', '>=', self::STAR_THRESHOLD)
            ->with(['team', 'player'])
            ->get()
            ->keyBy(fn (FixtureLineup $lineup): string => "{$lineup->fixture_id}-{$lineup->player_id}")
            ->all();
    }

    /**
     * Competition ranking on cumulative `total_points` descending: ties
     * share the better place (two managers tied on top are both rank 1),
     * and the next distinct total skips to `1 + count of managers above it`.
     *
     * @param  Collection<int, SeasonManager>  $managers
     * @param  array<int, int>  $cumulative  manager id => cumulative total_points
     * @return array<int, int> manager id => rank
     */
    private function ranksByManagerId(Collection $managers, array $cumulative): array
    {
        $ranks = [];

        foreach ($managers as $manager) {
            $ownTotal = $cumulative[$manager->id];
            $ahead = $managers->filter(fn (SeasonManager $other): bool => $cumulative[$other->id] > $ownTotal)->count();
            $ranks[$manager->id] = 1 + $ahead;
        }

        return $ranks;
    }
}
