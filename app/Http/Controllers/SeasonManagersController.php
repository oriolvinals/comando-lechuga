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
use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
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

    public function index(Request $request): Response
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

    public function show(SeasonManager $seasonManager): Response
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

        $lineupHistory = ManagerLineup::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('players.player.team')
            ->orderByDesc('week_number')
            ->get();

        $this->attachCurrentSeason($lineupHistory->flatMap(fn (ManagerLineup $lineup) => $lineup->players->pluck('player')), $season->id);
        $this->attachMatchFinished($lineupHistory, $season);
        $this->attachLineupPlayerScores($lineupHistory);
        $this->attachLineupFixtures($lineupHistory, $season);

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

        return Inertia::render('season-managers/show', [
            'season' => $season,
            'seasonManager' => $seasonManager,
            'roster' => $roster,
            'lineupHistory' => $lineupHistory,
            'startedWeeks' => $this->startedWeekNumbers($season),
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
}
