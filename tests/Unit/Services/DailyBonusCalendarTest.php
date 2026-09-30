<?php

use App\Services\DailyBonusCalendar;
use Carbon\CarbonImmutable;

test('pays 200.000 on break days and 100.000 on any other day', function (string $day, int $amount): void {
    expect((new DailyBonusCalendar)->amountOn(CarbonImmutable::parse($day)))->toBe($amount);
})->with([
    'day before the break' => ['2026-09-20', 100_000],
    'first break day' => ['2026-09-21', 200_000],
    'last break day' => ['2026-10-04', 200_000],
    'day after the break' => ['2026-10-05', 100_000],
    'preseason is a normal day' => ['2026-08-01', 100_000],
]);

test('adds every day from the joining day up to today, both included', function (): void {
    $calendar = new DailyBonusCalendar;
    $today = CarbonImmutable::parse('2026-09-30 12:00');

    // 31-jul … 30-sep: 62 days, 21…30-sep (10) are break days → 52 × 100.000 + 10 × 200.000.
    expect($calendar->totalSince(CarbonImmutable::parse('2026-07-31 20:55'), $today))->toBe(7_200_000)
        // 15-aug … 30-sep: 47 days, 10 in the break.
        ->and($calendar->totalSince(CarbonImmutable::parse('2026-08-15 14:25'), $today))->toBe(5_700_000)
        ->and($calendar->totalSince($today, $today))->toBe(200_000);
});
