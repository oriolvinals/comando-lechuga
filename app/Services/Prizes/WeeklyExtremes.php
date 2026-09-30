<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\SeasonClock;

/**
 * Which finished jornadas each manager ended first (or last) in. Ties all
 * count; only jornadas with at least two lineups; a manager without a
 * lineup that jornada is left out of it.
 */
final class WeeklyExtremes
{
    public function __construct(private readonly SeasonClock $clock) {}

    /**
     * @return array<int, list<int>> season manager id => week numbers, ascending
     */
    public function weeks(Season $season, bool $top): array
    {
        $weeks = $this->clock->finishedWeekNumbers($season);
        $result = [];

        if ($weeks === []) {
            return [];
        }

        ManagerLineup::query()
            ->whereIn('week_number', $weeks)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->orderBy('week_number')
            ->get(['season_manager_id', 'week_number', 'points'])
            ->groupBy('week_number')
            ->each(function ($lineups, $weekNumber) use ($top, &$result): void {
                if ($lineups->count() < 2) {
                    return;
                }

                $target = $top ? $lineups->max('points') : $lineups->min('points');

                $lineups
                    ->filter(fn (ManagerLineup $lineup): bool => $lineup->points === $target)
                    ->each(function (ManagerLineup $lineup) use ($weekNumber, &$result): void {
                        $result[$lineup->season_manager_id][] = (int) $weekNumber;
                    });
            });

        return $result;
    }
}
