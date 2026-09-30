<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/** How many finished jornadas each manager ended last in. */
final class ElPupas implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly WeeklyExtremes $extremes) {}

    public function rows(Season $season): array
    {
        $weeks = $this->extremes->weeks($season, top: false);

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, count($weeks[$id] ?? []), ['weeks' => $weeks[$id] ?? []]), $this->managerIds($season));
    }
}
