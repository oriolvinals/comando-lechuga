<?php

declare(strict_types=1);

namespace App\Services\Prizes;

/** The worst single finished jornada of each manager (La Noche Negra); the earlier one on a tie. */
final class WorstNight extends ExtremeNight
{
    protected function beats(int $points, int $current): bool
    {
        return $points < $current;
    }
}
