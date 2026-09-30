<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/**
 * Each manager's longest run of consecutive finished jornadas with the same
 * player in his lineup; the latest run on a tie. `alive` when it reaches
 * the last finished jornada.
 */
final class LongestPartnership implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    public function rows(Season $season): array
    {
        $weeks = $this->clock->finishedWeekNumbers($season);

        /** @var array<int, array<int, list<int>>> $weeksByManagerPlayer */
        $weeksByManagerPlayer = [];

        if ($weeks !== []) {
            ManagerLineup::query()
                ->with('players:id,manager_lineup_id,player_id')
                ->whereIn('week_number', $weeks)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
                ->orderBy('week_number')
                ->get(['id', 'season_manager_id', 'week_number'])
                ->each(function (ManagerLineup $lineup) use (&$weeksByManagerPlayer): void {
                    foreach ($lineup->players as $entry) {
                        $weeksByManagerPlayer[$lineup->season_manager_id][$entry->player_id][] = $lineup->week_number;
                    }
                });
        }

        $lastWeek = $weeks === [] ? 0 : max($weeks);

        return array_map(function (int $id) use ($weeksByManagerPlayer, $lastWeek): PrizeRow {
            $best = null;

            foreach ($weeksByManagerPlayer[$id] ?? [] as $playerId => $playerWeeks) {
                $start = null;
                $previous = null;

                foreach ($playerWeeks as $week) {
                    $start = $previous !== null && $week === $previous + 1 ? $start : $week;
                    $previous = $week;
                    $length = $week - $start + 1;

                    if ($best === null || $length > $best['length'] || ($length === $best['length'] && $week > $best['to_week'])) {
                        $best = ['length' => $length, 'player_id' => (int) $playerId, 'from_week' => $start, 'to_week' => $week];
                    }
                }
            }

            if ($best === null) {
                return new PrizeRow($id, null);
            }

            return new PrizeRow($id, $best['length'], [
                'player_id' => $best['player_id'],
                'from_week' => $best['from_week'],
                'to_week' => $best['to_week'],
                'alive' => $best['to_week'] === $lastWeek,
            ]);
        }, $this->managerIds($season));
    }
}
