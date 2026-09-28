<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesLineupFixtures;
use App\Http\Controllers\Concerns\AttachesLineupPlayerScores;
use App\Http\Controllers\Concerns\AttachesMatchFinished;
use App\Http\Controllers\Concerns\AttachesNextFixtures;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Activity;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ManagerShields;
use App\Services\ManagerWeekRanks;
use App\Services\StartProbabilities;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeasonManagersController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesCurrentPlayerSeason;
    use AttachesLineupFixtures;
    use AttachesLineupPlayerScores;
    use AttachesMatchFinished;
    use AttachesNextFixtures;
    use AttachesRecentScores;
    use FiltersSeasonWeeks;
    use ResolvesRequestedWeek;

    public function index(Request $request, StartProbabilities $startProbabilities, ManagerShields $managerShields): Response
    {
        $season = Season::current();
        $week = $this->resolveWeek($request, $season);

        $lineups = ManagerLineup::query()
            ->where('week_number', $week)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with(['seasonManager', 'players.player.team'])
            ->orderByDesc('points')
            ->get();

        $this->attachCurrentSeason($lineups->flatMap(fn (ManagerLineup $lineup) => $lineup->players->pluck('player')), $season->id);
        $this->attachMatchFinished($lineups, $season);
        $this->attachLineupPlayerScores($lineups);
        $this->attachLineupFixtures($lineups, $season);
        $this->attachLineupStarts($lineups, $startProbabilities);

        $shields = $managerShields->forSeason($season);
        $lineups->each(function (ManagerLineup $lineup) use ($shields, $week): void {
            $lineup->seasonManager->shields = ManagerShields::inWeek($shields, $lineup->season_manager_id, $week);
        });

        return Inertia::render('season-managers/index', [
            'season' => $season,
            'filters' => ['week' => $week],
            'lineups' => $lineups,
            // Cast to object: PHP normalizes numeric string keys back to
            // int, so a plain array here could serialize as a sparse JSON
            // array instead of the {"1": "all", ...} object the frontend expects.
            'weekProgress' => (object) $this->weekProgress($season),
        ]);
    }

    public function show(SeasonManager $seasonManager, StartProbabilities $startProbabilities, ManagerWeekRanks $managerWeekRanks, ManagerShields $managerShields): Response
    {
        $season = Season::current();

        if (!$this->currentWeekIsLive($season)) {
            $seasonManager->live_points = null;
        }

        $roster = ManagerPlayer::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('player.team')
            ->get();

        $this->attachCurrentSeason($roster->pluck('player'), $season->id);
        $this->attachRecentScores($roster->pluck('player'), $season, $seasonManager->id);
        $this->attachNextFixtures($roster->pluck('player'), $season);

        $nextStarts = $startProbabilities->forPlayersNextFixture($roster->pluck('player'), $season);

        $roster->each(function (ManagerPlayer $entry) use ($nextStarts): void {
            $entry->player->next_start = $nextStarts[$entry->player->id] ?? null;
        });

        $lineupHistory = ManagerLineup::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('players.player.team')
            ->orderByDesc('week_number')
            ->get();

        $this->attachCurrentSeason($lineupHistory->flatMap(fn (ManagerLineup $lineup) => $lineup->players->pluck('player')), $season->id);
        $this->attachMatchFinished($lineupHistory, $season);
        $this->attachLineupPlayerScores($lineupHistory);
        $this->attachLineupFixtures($lineupHistory, $season);
        $this->attachLineupStarts($lineupHistory, $startProbabilities);

        $activity = Activity::query()
            ->where(fn ($query) => $query
                ->where('source_season_manager_id', $seasonManager->id)
                ->orWhere('target_season_manager_id', $seasonManager->id))
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

        $weekExtremes = $this->weekNumberExtremes($seasonManager, $season);
        $startedWeeks = $this->startedWeekNumbers($season);
        $weekRanks = $managerWeekRanks->forManager($seasonManager, $season, $startedWeeks);

        return Inertia::render('season-managers/show', [
            'season' => $season,
            'seasonManager' => $seasonManager,
            'roster' => $roster,
            'lineupHistory' => $lineupHistory,
            'startedWeeks' => $startedWeeks,
            // Cast to object for the same reason as weekProgress below.
            'weekRanks' => (object) $weekRanks,
            // Shields used per jornada, up to the current shield jornada.
            // Cast to object for the same reason as weekProgress below.
            'weekShields' => (object) ManagerShields::usedByWeek(
                $managerShields->forSeason($season),
                $seasonManager->id,
            ),
            'weeklySummary' => $this->weeklySummary($seasonManager, $weekRanks),
            // Cast to object: PHP normalizes numeric string keys back to
            // int, so a plain array here could serialize as a sparse JSON
            // array instead of the {"1": "all", ...} object the frontend expects.
            'weekProgress' => (object) $this->weekProgress($season),
            'wonWeeks' => $weekExtremes['won'],
            'lostWeeks' => $weekExtremes['lost'],
            'activity' => $activity,
        ]);
    }

    /**
     * Season points per started jornada with a lineup, and the best such jornada
     * (the earliest one on a tie) — null values while there are none.
     *
     * @param  array<int, array{rank: int, managers: int, points: int, is_last: bool}>  $weekRanks
     * @return array{played_weeks: int, average_points: float|null, best_week: array{week_number: int, points: int, rank: int, managers: int}|null}
     */
    private function weeklySummary(SeasonManager $seasonManager, array $weekRanks): array
    {
        $bestWeek = null;

        foreach ($weekRanks as $weekNumber => $weekRank) {
            if ($bestWeek === null || $weekRank['points'] > $bestWeek['points']) {
                $bestWeek = [
                    'week_number' => $weekNumber,
                    'points' => $weekRank['points'],
                    'rank' => $weekRank['rank'],
                    'managers' => $weekRank['managers'],
                ];
            }
        }

        $playedWeeks = count($weekRanks);

        return [
            'played_weeks' => $playedWeeks,
            'average_points' => $playedWeeks > 0 ? round($seasonManager->total_points / $playedWeeks, 2) : null,
            'best_week' => $bestWeek,
        ];
    }

    /**
     * Finished week numbers where this manager topped ("won") or bottomed
     * ("lost") every manager's lineup points that week — ties all count on
     * both ends, matching the existing win logic.
     *
     * @return array{won: array<int, int>, lost: array<int, int>}
     */
    private function weekNumberExtremes(SeasonManager $seasonManager, Season $season): array
    {
        $finishedWeeks = $this->finishedWeekNumbers($season);

        if ($finishedWeeks === []) {
            return ['won' => [], 'lost' => []];
        }

        $lineups = ManagerLineup::query()
            ->whereIn('week_number', $finishedWeeks)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get(['season_manager_id', 'week_number', 'points']);

        $lineupsByWeek = $lineups->groupBy('week_number');
        $maxPointsByWeek = $lineupsByWeek->map(fn ($weekLineups) => $weekLineups->max('points'))->all();
        $minPointsByWeek = $lineupsByWeek->map(fn ($weekLineups) => $weekLineups->min('points'))->all();

        $ownLineups = $lineups->where('season_manager_id', $seasonManager->id);

        /**
         * @param  array<int, int>  $extremePointsByWeek
         * @return array<int, int>
         */
        $weekNumbersAt = fn (array $extremePointsByWeek): array => $ownLineups
            ->filter(fn (ManagerLineup $lineup): bool => $lineup->points === $extremePointsByWeek[$lineup->week_number])
            ->pluck('week_number')
            ->sort()
            ->values()
            ->all();

        return [
            'won' => $weekNumbersAt($maxPointsByWeek),
            'lost' => $weekNumbersAt($minPointsByWeek),
        ];
    }

    /**
     * Attaches each lineup entry's own-fixture start facts
     * (`ManagerLineupPlayer::$start`), batched across every entry in the
     * given lineups. Expects `attachLineupFixtures()` to already have
     * resolved each entry's `fixture`.
     *
     * @param  Collection<int, ManagerLineup>  $lineups
     */
    private function attachLineupStarts(Collection $lineups, StartProbabilities $startProbabilities): void
    {
        $entries = $lineups->flatMap(fn (ManagerLineup $lineup) => $lineup->players);
        $starts = $startProbabilities->forLineupEntries($entries);

        $entries->each(function (ManagerLineupPlayer $entry) use ($starts): void {
            $entry->start = $starts[$entry->id] ?? null;
        });
    }
}
