<?php

use App\Services\MaxBidCalculator;

test('projects the daily increment decaying 10 % per day over the 14-day lock', function (): void {
    $projection = MaxBidCalculator::project(1000, 100.0);

    expect($projection)->toHaveCount(15)
        ->and($projection[0])->toBe(1000)
        ->and($projection[1])->toBe(1090)
        ->and($projection[2])->toBe(1171)
        ->and($projection[14])->toBe(1694);
});

test('the bid is what the best of 14 uniform ±10 % offers beats with 75 % probability', function (): void {
    $flat = array_fill(0, 15, 1_000_000);

    $bid = MaxBidCalculator::solveBid($flat);

    expect($bid)->toEqualWithDelta(1_081_145, 2)
        ->and(1 - MaxBidCalculator::bestOfferProbabilityAtMost($bid, $flat))->toEqualWithDelta(0.75, 0.0001);
});

test('an offer can never beat 110 % of the highest projected value', function (): void {
    expect(MaxBidCalculator::bestOfferProbabilityAtMost(1_100_000, array_fill(0, 15, 1_000_000)))->toBe(1.0)
        ->and(MaxBidCalculator::bestOfferProbabilityAtMost(900_000, array_fill(0, 15, 1_000_000)))->toBe(0.0);
});
