<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * The daily bonus a manager claims by pressing a button once a day:
 * 100.000 €, or 200.000 € during a break without LaLiga jornadas. The feed
 * doesn't record it, but managers normally claim it, so the balance model
 * assumes every day since joining was claimed.
 */
final class DailyBonusCalendar
{
    public const int REGULAR_AMOUNT = 100_000;

    public const int BREAK_AMOUNT = 200_000;

    /**
     * Inclusive date ranges without LaLiga jornadas. Add new breaks here.
     *
     * @var list<array{0: string, 1: string}>
     */
    public const array BREAKS = [
        ['2026-09-21', '2026-10-04'],
    ];

    public function amountOn(CarbonImmutable $day): int
    {
        $date = $day->toDateString();

        foreach (self::BREAKS as [$from, $to]) {
            if ($date >= $from && $date <= $to) {
                return self::BREAK_AMOUNT;
            }
        }

        return self::REGULAR_AMOUNT;
    }

    /** Every day from the joining day up to today, both included. */
    public function totalSince(CarbonImmutable $joinedAt, CarbonImmutable $today): int
    {
        $total = 0;
        $lastDay = $today->startOfDay();

        for ($day = $joinedAt->startOfDay(); $day->lessThanOrEqualTo($lastDay); $day = $day->addDay()) {
            $total += $this->amountOn($day);
        }

        return $total;
    }
}
