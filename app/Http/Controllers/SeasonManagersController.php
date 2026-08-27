<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonManager;
use App\Models\SeasonManagerLineup;
use App\Models\SeasonManagerPlayer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeasonManagersController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesRecentScores;
    use FiltersSeasonWeeks;
    use ResolvesRequestedWeek;

    public function index(Request $request): Response
    {
        $season = Season::current();
        $week = $this->resolveWeek($request, $season);

        $lineups = SeasonManagerLineup::query()
            ->where('week_number', $week)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with(['seasonManager', 'players.player.team'])
            ->orderByDesc('points')
            ->get();

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

        $roster = SeasonManagerPlayer::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('player.team')
            ->get();

        $this->attachRecentScores($roster->pluck('player'), $season, $seasonManager->id);

        $lineupHistory = SeasonManagerLineup::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('players.player.team')
            ->orderByDesc('week_number')
            ->get();

        $activity = SeasonActivity::query()
            ->where(fn ($query) => $query
                ->where('source_season_manager_id', $seasonManager->id)
                ->orWhere('target_season_manager_id', $seasonManager->id))
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

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
            'wonWeeks' => $this->wonWeekNumbers($seasonManager, $season),
            'activity' => $activity,
        ]);
    }

    /**
     * Finished week numbers where this manager topped every manager's lineup
     * points that week (ties all count as winners).
     *
     * @return array<int, int>
     */
    private function wonWeekNumbers(SeasonManager $seasonManager, Season $season): array
    {
        $finishedWeeks = $this->finishedWeekNumbers($season);

        if ($finishedWeeks === []) {
            return [];
        }

        $lineups = SeasonManagerLineup::query()
            ->whereIn('week_number', $finishedWeeks)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get(['season_manager_id', 'week_number', 'points']);

        $maxPointsByWeek = $lineups
            ->groupBy('week_number')
            ->map(fn ($weekLineups) => $weekLineups->max('points'));

        return $lineups
            ->where('season_manager_id', $seasonManager->id)
            ->filter(fn (SeasonManagerLineup $lineup): bool => $lineup->points === $maxPointsByWeek[$lineup->week_number])
            ->pluck('week_number')
            ->sort()
            ->values()
            ->all();
    }
}
