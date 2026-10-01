<?php

use App\Services\MaxBidCalibrationFit;
use App\Services\MaxBidParameters;

test('interpolates each knot between the two curve points around its target', function (): void {
    $knots = MaxBidCalibrationFit::knotsFromCurve([[0.5, 0.4], [0.7, 0.6], [0.99, 0.99]]);

    expect($knots[50])->toEqualWithDelta(0.6, 1e-4)
        ->and($knots[55])->toEqualWithDelta(0.65, 1e-4)
        ->and($knots[75])->toEqualWithDelta(0.7 + 0.15 / 0.39 * 0.29, 1e-4);
});

test('floors a target already met at 50 % and caps an unreachable one at the highest confidence', function (): void {
    $knots = MaxBidCalibrationFit::knotsFromCurve([[0.5, 0.7], [0.99, 0.8]]);

    expect($knots[50])->toBe(0.5)
        ->and($knots[70])->toBe(0.5)
        ->and($knots[85])->toBe(MaxBidCalibrationFit::MAX_CONFIDENCE)
        ->and($knots[95])->toBe(MaxBidCalibrationFit::MAX_CONFIDENCE);
});

test('never lets a knot fall below the previous one, even on a jagged curve', function (): void {
    $knots = MaxBidCalibrationFit::knotsFromCurve([[0.5, 0.45], [0.6, 0.8], [0.7, 0.55], [0.8, 0.85], [0.9, 0.6], [0.99, 0.97]]);
    $previous = 0.0;

    foreach (range(50, 95, 5) as $percent) {
        expect($knots[$percent])->toBeGreaterThanOrEqual($previous);
        $previous = $knots[$percent];
    }

    expect(array_keys($knots))->toBe(range(50, 95, 5))
        ->and(new MaxBidParameters(confidenceCalibration: $knots))->toBeInstanceOf(MaxBidParameters::class);
});

test('fits the identity when the projection is the real path', function (): void {
    $path = [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000, 10_600_000, 10_700_000, 10_800_000, 10_900_000, 11_000_000, 11_100_000, 11_200_000, 11_300_000, 11_400_000];
    $knots = MaxBidCalibrationFit::fit([[$path, $path]]);

    expect($knots[50])->toEqualWithDelta(0.5, 0.005)
        ->and($knots[75])->toEqualWithDelta(0.75, 0.005)
        ->and($knots[95])->toEqualWithDelta(0.95, 0.005);
});

test('fits nothing without any case', function (): void {
    expect(MaxBidCalibrationFit::fit([]))->toBeNull();
});
