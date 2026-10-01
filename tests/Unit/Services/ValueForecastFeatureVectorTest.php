<?php

use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use App\Services\ValueForecast\ValueForecastParameters;

test('buckets a match like the research', function (array $match, ?string $bucket): void {
    expect(Vector::bucket($match))->toBe($bucket);
})->with([
    'no match' => [['team' => false, 'played' => false, 'points' => 0], null],
    'did not play' => [['team' => true, 'played' => false, 'points' => 0], 'nomin'],
    '2 pts' => [['team' => true, 'played' => true, 'points' => 2], 'low'],
    'negative' => [['team' => true, 'played' => true, 'points' => -1], 'low'],
    '5 pts' => [['team' => true, 'played' => true, 'points' => 5], 'mid'],
    '9 pts' => [['team' => true, 'played' => true, 'points' => 9], 'good'],
    '10 pts' => [['team' => true, 'played' => true, 'points' => 10], 'great'],
]);

test('builds the 30 variables in the research order', function (): void {
    $row = forecastRow([
        'value' => 10_000_000,
        'matchYesterday' => ['team' => true, 'played' => true, 'points' => 12],
        'matchToday' => ['team' => true, 'played' => false, 'points' => 0],
        'daysToNextMatch' => 1,
    ]);
    $logValue = 7.0 - 6.5;

    $x = Vector::of($row);

    expect($x)->toHaveCount(Vector::SIZE)
        ->and(array_slice($x, 0, 6))->toEqualWithDelta([1.0, 0.02, 0.01, 0.005, -0.007, 0.01], 1e-12)
        ->and(array_slice($x, Vector::YESTERDAY, 5))->toBe([0.0, 0.0, 0.0, 0.0, 1.0])
        ->and(array_slice($x, Vector::TODAY, 5))->toBe([1.0, 0.0, 0.0, 0.0, 0.0])
        ->and(array_slice($x, Vector::BEFORE, 5))->toBe([0.0, 0.0, 0.0, 0.0, 0.0])
        ->and($x[Vector::POINTS_VS_AVERAGE])->toEqualWithDelta(0.8, 1e-12)
        ->and([$x[Vector::MATCH_TOMORROW], $x[Vector::MATCH_WITHIN_3_DAYS], $x[Vector::BREAK_OVER_7_DAYS]])->toBe([1.0, 1.0, 0.0])
        ->and($x[Vector::LOG_VALUE])->toEqualWithDelta($logValue, 1e-12)
        ->and($x[Vector::CHANGE_X_LOG_VALUE])->toEqualWithDelta(0.02 * $logValue, 1e-12)
        ->and($x[Vector::AT_FLOOR])->toBe(0.0)
        ->and($x[Vector::CHANGE_IF_MATCH_YESTERDAY])->toEqualWithDelta(0.02, 1e-12)
        ->and($x[Vector::POINTS_X_LOG_VALUE])->toEqualWithDelta(1.2 * $logValue, 1e-12);
});

test('clamps yesterday\'s points to −5…20 in the interaction and flags the floor', function (): void {
    $x = Vector::of(forecastRow([
        'changeToday' => -0.034,
        'matchYesterday' => ['team' => true, 'played' => true, 'points' => 25],
        'daysToNextMatch' => 12,
    ]));

    expect($x[Vector::POINTS_X_LOG_VALUE])->toEqualWithDelta(2.0 * 0.5, 1e-12)
        ->and($x[Vector::AT_FLOOR])->toBe(1.0)
        ->and($x[Vector::BREAK_OVER_7_DAYS])->toBe(1.0);
});

test('the fitted target is the change over persistence, clipped to ±10 %', function (): void {
    $parameters = new ValueForecastParameters;

    expect(Vector::target(forecastRow(['nextValue' => 10_300_000]), $parameters))->toEqualWithDelta(0.03 - 0.02, 1e-12)
        ->and(Vector::target(forecastRow(['nextValue' => 13_000_000]), $parameters))->toBe(0.1)
        ->and(forecastRow(['nextValue' => null])->actualChange())->toBeNull();
});

test('matches the research feats of backtest.js within 1e-12', function (array $overrides, array $expected): void {
    // The expected vectors are what comando-lechuga-research/value-forecast/backtest.js
    // `feats(r)` returns under Node for the same rows (printed to 17 digits).
    expect(Vector::of(forecastRow($overrides)))->toEqualWithDelta($expected, 1e-12);
})->with([
    'riser after a great match' => [
        [
            'value' => 23_456_789, 'changeToday' => 0.0312, 'changeYesterday' => -0.0045, 'changeBefore' => 0.0101, 'marketChange' => 0.0023,
            'matchYesterday' => ['team' => true, 'played' => true, 'points' => 14],
            'matchToday' => ['team' => false, 'played' => false, 'points' => 0],
            'matchBefore' => ['team' => true, 'played' => false, 'points' => 0],
            'daysToNextMatch' => 3, 'averagePoints' => 5.75,
        ],
        [1, 0.0312, -0.0045, 0.0101, 0.0023, 0.035699999999999996, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0.825, 0, 1, 0, 0.8702685612737584, 0.02715237911174126, 0, 0.0312, 1.2183759857832617],
    ],
    'faller at the floor after negative points' => [
        [
            'value' => 612_345, 'changeToday' => -0.0346, 'changeYesterday' => -0.0211, 'changeBefore' => 0.0, 'marketChange' => -0.0051,
            'matchYesterday' => ['team' => true, 'played' => true, 'points' => -3],
            'matchToday' => ['team' => true, 'played' => true, 'points' => 4],
            'matchBefore' => ['team' => false, 'played' => false, 'points' => 0],
            'daysToNextMatch' => 8, 'averagePoints' => 1.5,
        ],
        [1, -0.0346, -0.0211, 0, -0.0051, -0.013499999999999998, 0, 1, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, -0.45, 0, 0, 1, -0.7130038239652619, 0.024669932309198058, 1, -0.0346, 0.21390114718957856],
    ],
    'no match yesterday, match tomorrow' => [
        [
            'value' => 4_100_000, 'changeToday' => 0.0007, 'changeYesterday' => 0.0013, 'changeBefore' => 0.0029, 'marketChange' => 0.0004,
            'matchYesterday' => ['team' => false, 'played' => false, 'points' => 0],
            'matchToday' => ['team' => true, 'played' => true, 'points' => 7],
            'matchBefore' => ['team' => true, 'played' => true, 'points' => 22],
            'daysToNextMatch' => 1, 'averagePoints' => 0.0,
        ],
        [1, 0.0007, 0.0013, 0.0029, 0.0004, -0.0006, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 1, 0, 1, 1, 0, 0.11278385671973545, 0.00007894869970381482, 0, 0, 0],
    ],
]);
