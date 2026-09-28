<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;

/**
 * A manager's place among the season's managers in each given jornada he
 * has a lineup for. Shared by the manager ficha and the public API.
 */
class ManagerWeekRanks
{
    /**
     * Ranked by lineup points. Ties share the better place (two managers
     * tied on top are both 1º); `is_last` flags a share of the bottom.
     * Jornadas without this manager's lineup are skipped.
     *
     * @param  array<int, int>  $weekNumbers
     * @return array<int, array{rank: int, managers: int, points: int, is_last: bool}>
     */
    public function forManager(SeasonManager $seasonManager, Season $season, array $weekNumbers): array
    {
        if ($weekNumbers === []) {
            return [];
        }

        $lineupsByWeek = ManagerLineup::query()
            ->whereIn('week_number', $weekNumbers)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get(['season_manager_id', 'week_number', 'points'])
            ->groupBy('week_number');

        $weekRanks = [];

        foreach ($lineupsByWeek as $weekNumber => $weekLineups) {
            $ownLineup = $weekLineups->firstWhere('season_manager_id', $seasonManager->id);

            if (!$ownLineup instanceof ManagerLineup) {
                continue;
            }

            $weekRanks[(int) $weekNumber] = [
                'rank' => 1 + $weekLineups->filter(fn (ManagerLineup $lineup): bool => $lineup->points > $ownLineup->points)->count(),
                'managers' => $weekLineups->count(),
                'points' => $ownLineup->points,
                'is_last' => $weekLineups->count() > 1 && $ownLineup->points === $weekLineups->min('points'),
            ];
        }

        ksort($weekRanks);

        return $weekRanks;
    }
}
