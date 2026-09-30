<?php

declare(strict_types=1);

namespace App\Services\Prizes\Concerns;

use App\Models\Season;
use App\Models\SeasonManager;

trait ListsSeasonManagers
{
    /**
     * @return list<int>
     */
    private function managerIds(Season $season): array
    {
        /** @var list<int> */
        return SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('position')
            ->pluck('id')
            ->all();
    }

    /**
     * The most repeated manager id (first seen wins a tie); null when none.
     *
     * @param  list<int|null>  $ids
     * @return array{season_manager_id: int, count: int}|null
     */
    private function mostFrequent(array $ids): ?array
    {
        $counts = array_count_values(array_filter($ids, fn (?int $id): bool => $id !== null));

        if ($counts === []) {
            return null;
        }

        arsort($counts);
        $id = array_key_first($counts);

        return ['season_manager_id' => (int) $id, 'count' => $counts[$id]];
    }
}
