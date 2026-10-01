<?php

use App\Services\MaxBidParameters;

test('without a calibration the chosen confidence is used as is', function (): void {
    expect((new MaxBidParameters(confidenceCalibration: []))->effectiveConfidence(0.75))->toBe(0.75);
});

test('the defaults carry the calibration fitted for a real 75 %', function (): void {
    $defaults = new MaxBidParameters;

    expect($defaults->effectiveConfidence(0.75))->toBe(0.9654)
        ->and($defaults->incrementShrink)->toBe(0.8);
});

test('interpolates between the knots and clamps to 50–95 %', function (): void {
    $parameters = new MaxBidParameters(confidenceCalibration: calibrationKnots(0.15));

    expect($parameters->effectiveConfidence(0.75))->toBe(0.9)
        ->and($parameters->effectiveConfidence(0.775))->toEqualWithDelta(0.925, 1e-9)
        ->and($parameters->effectiveConfidence(0.95))->toBe(0.99)
        ->and($parameters->effectiveConfidence(0.4))->toBe(0.65);
});

test('rejects an incomplete, out-of-range or decreasing calibration and a bad shrink', function (array $arguments): void {
    expect(fn () => new MaxBidParameters(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'missing knot' => [['confidenceCalibration' => [50 => 0.6, 95 => 0.99]]],
    'out of range' => [['confidenceCalibration' => array_replace(calibrationKnots(0.0), [95 => 1.0])]],
    'decreasing' => [['confidenceCalibration' => array_replace(calibrationKnots(0.0), [60 => 0.5])]],
    'zero shrink' => [['incrementShrink' => 0.0]],
    'shrink above one' => [['incrementShrink' => 1.2]],
]);
