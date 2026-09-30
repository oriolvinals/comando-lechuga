<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/** Clauses each manager paid (buyout source), with the manager he robbed most. */
final class ElAtracador implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function rows(Season $season): array
    {
        $buyouts = $this->buyouts($season);

        return array_map(function (int $id) use ($buyouts): PrizeRow {
            $paid = $buyouts->where('source_season_manager_id', $id);

            /** @var list<int|null> $victims */
            $victims = $paid->pluck('target_season_manager_id')->values()->all();

            return new PrizeRow($id, $paid->count(), ['favourite' => $this->mostFrequent($victims)]);
        }, $this->managerIds($season));
    }
}
