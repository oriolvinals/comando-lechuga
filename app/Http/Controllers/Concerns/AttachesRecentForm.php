<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Support\Collection;

trait AttachesRecentForm
{
    use FiltersSeasonWeeks;

    /**
     * Attaches each manager's points for its last 3 finished jornadas (oldest
     * first, left to right) — the "forma" column in the home standings. A
     * jornada still in progress is excluded, not just one that hasn't
     * started: its points are provisional, not a finished result. Shorter
     * than 3 — padded with null at the end — only for a manager without 3
     * finished jornadas yet.
     *
     * @param  Collection<int, SeasonManager>  $standings
     */
    private function attachRecentForm(Collection $standings, Season $season): void
    {
        $lineupsByManager = ManagerLineup::query()
            ->whereIn('season_manager_id', $standings->pluck('id'))
            ->whereIn('week_number', $this->finishedWeekNumbers($season))
            ->get()
            ->groupBy('season_manager_id');

        $standings->each(function (SeasonManager $manager) use ($lineupsByManager): void {
            $recent = ($lineupsByManager->get($manager->id) ?? collect())
                ->sortByDesc('week_number')
                ->take(3)
                ->sortBy('week_number')
                ->values()
                ->map(fn (ManagerLineup $lineup): int => $lineup->points)
                ->all();

            /** @var array<int, int|null> $paddedRecent */
            $paddedRecent = array_pad($recent, 3, null);
            $manager->recent_form = $paddedRecent;
        });
    }
}
