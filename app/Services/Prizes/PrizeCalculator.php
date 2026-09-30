<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;

interface PrizeCalculator
{
    /**
     * One row per manager of the season, in any order.
     *
     * @return list<PrizeRow>
     */
    public function rows(Season $season): array;
}
