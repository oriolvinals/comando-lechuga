<?php

declare(strict_types=1);

use App\Enums\SeasonPrize;

test('the ten prizes add up to the 70 euro pot in page order', function (): void {
    expect(array_map(fn (SeasonPrize $prize): string => $prize->value, SeasonPrize::cases()))->toBe([
        'best_night', 'most_buyouts_made', 'sunday_king', 'bench_points',
        'most_overpaid', 'most_buyouts_suffered', 'worst_weeks', 'longest_partnership', 'most_owned_player', 'open_slot',
    ])
        ->and(array_sum(array_map(fn (SeasonPrize $prize): int => $prize->amount(), SeasonPrize::cases())))->toBe(70)
        ->and(SeasonPrize::BestNight->amount())->toBe(10)
        ->and(SeasonPrize::MostOverpaid->amount())->toBe(5);
});

test('only the free slot is undecided', function (): void {
    expect(SeasonPrize::OpenSlot->isDecided())->toBeFalse()
        ->and(SeasonPrize::LongestPartnership->isDecided())->toBeTrue()
        ->and(SeasonPrize::MostOwnedPlayer->label())->toBe('El Fichaje del Pueblo');
});
