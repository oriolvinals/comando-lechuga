<?php

declare(strict_types=1);

namespace App\Services\Prizes;

/** The best single finished jornada of each manager; the earlier one on a tie. */
final class BestNight extends ExtremeNight
{
    protected function beats(int $points, int $current): bool
    {
        return $points > $current;
    }
}
