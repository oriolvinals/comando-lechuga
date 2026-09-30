<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/** The best single finished jornada of each manager; the earlier one on a tie. */
final class NocheMagica implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    public function rows(Season $season): array
    {
        $weeks = $this->clock->finishedWeekNumbers($season);

        /** @var array<int, array{points: int, week_number: int}> $best */
        $best = [];

        if ($weeks !== []) {
            ManagerLineup::query()
                ->whereIn('week_number', $weeks)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
                ->orderBy('week_number')
                ->get(['season_manager_id', 'week_number', 'points'])
                ->each(function (ManagerLineup $lineup) use (&$best): void {
                    $current = $best[$lineup->season_manager_id] ?? null;

                    if ($current === null || $lineup->points > $current['points']) {
                        $best[$lineup->season_manager_id] = ['points' => $lineup->points, 'week_number' => $lineup->week_number];
                    }
                });
        }

        return array_map(fn (int $id): PrizeRow => isset($best[$id])
            ? new PrizeRow($id, $best[$id]['points'], ['week_number' => $best[$id]['week_number']])
            : new PrizeRow($id, null), $this->managerIds($season));
    }
}
