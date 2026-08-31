<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Http\Controllers\Controller;
use App\Http\Resources\StandingsResource;
use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StandingsController extends Controller
{
    use FiltersSeasonWeeks;

    public function index(): AnonymousResourceCollection
    {
        $season = Season::current();

        $standings = SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('position')
            ->get();

        $this->attachApiRecentForm($standings, $season);

        return StandingsResource::collection($standings);
    }

    /**
     * Builds each manager's `api_recent_form` — see the property's docblock
     * on {@see SeasonManager} for the exact shape and live-jornada rule.
     *
     * @param  Collection<int, SeasonManager>  $standings
     */
    private function attachApiRecentForm(Collection $standings, Season $season): void
    {
        $finishedWeeks = $this->finishedWeekNumbers($season);
        $isLive = $this->currentWeekStarted($season) && !in_array($season->current_week, $finishedWeeks, true);

        $lineupsByManager = ManagerLineup::query()
            ->whereIn('season_manager_id', $standings->pluck('id'))
            ->whereIn('week_number', $finishedWeeks)
            ->get()
            ->groupBy('season_manager_id');

        $take = $isLive ? 2 : 3;

        $standings->each(function (SeasonManager $manager) use ($lineupsByManager, $take, $isLive, $season): void {
            $recent = ($lineupsByManager->get($manager->id) ?? collect())
                ->sortByDesc('week_number')
                ->take($take)
                ->sortBy('week_number')
                ->values()
                ->map(fn (ManagerLineup $lineup): array => [
                    'week_number' => $lineup->week_number,
                    'points' => $lineup->points,
                    'live' => false,
                ])
                ->all();

            if ($isLive) {
                $recent[] = [
                    'week_number' => $season->current_week,
                    'points' => $manager->live_points,
                    'live' => true,
                ];
            }

            $manager->api_recent_form = $recent;
        });
    }
}
