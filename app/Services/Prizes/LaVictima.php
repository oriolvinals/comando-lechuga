<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/** Clauses paid against each manager (buyout target), with who paid most. */
final class LaVictima implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function rows(Season $season): array
    {
        $buyouts = $this->buyouts($season);

        return array_map(function (int $id) use ($buyouts): PrizeRow {
            $received = $buyouts->where('target_season_manager_id', $id);

            /** @var list<int|null> $payers */
            $payers = $received->pluck('source_season_manager_id')->values()->all();

            return new PrizeRow($id, $received->count(), ['nemesis' => $this->mostFrequent($payers)]);
        }, $this->managerIds($season));
    }
}
