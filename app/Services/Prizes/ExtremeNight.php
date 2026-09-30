<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/** The extreme single finished jornada of each manager; the earlier one on a tie. */
abstract class ExtremeNight implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    /** Whether a jornada's points beat the manager's current extreme. */
    abstract protected function beats(int $points, int $current): bool;

    public function rows(Season $season): array
    {
        $weeks = $this->clock->finishedWeekNumbers($season);

        /** @var array<int, array{points: int, week_number: int}> $extreme */
        $extreme = [];

        if ($weeks !== []) {
            ManagerLineup::query()
                ->whereIn('week_number', $weeks)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
                ->orderBy('week_number')
                ->get(['season_manager_id', 'week_number', 'points'])
                ->each(function (ManagerLineup $lineup) use (&$extreme): void {
                    $current = $extreme[$lineup->season_manager_id] ?? null;

                    if ($current === null || $this->beats($lineup->points, $current['points'])) {
                        $extreme[$lineup->season_manager_id] = ['points' => $lineup->points, 'week_number' => $lineup->week_number];
                    }
                });
        }

        return array_map(fn (int $id): PrizeRow => isset($extreme[$id])
            ? new PrizeRow($id, $extreme[$id]['points'], ['week_number' => $extreme[$id]['week_number']])
            : new PrizeRow($id, null), $this->managerIds($season));
    }
}
