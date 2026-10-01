<?php

use App\Services\ValueForecast\HybridRegression;

test('recovers exact coefficients without ridge', function (): void {
    $regression = new HybridRegression(2, 0.0);

    foreach ([-2.0, -1.0, 0.0, 1.0, 3.0] as $x) {
        $regression->add([1.0, $x], 2.0 + 3.0 * $x);
    }

    [$intercept, $slope] = $regression->solve();

    expect($regression->count())->toBe(5)
        ->and($intercept)->toEqualWithDelta(2.0, 1e-9)
        ->and($slope)->toEqualWithDelta(3.0, 1e-9);
});

test('ridge adds lambda times the row count to every diagonal term but the intercept', function (): void {
    // XᵀX = [[2, 0], [0, 2]], Xᵀy = [0, 2]; λ·n = 0,5·2 = 1 → slope 2 / 3, intercept untouched at 0.
    $regression = new HybridRegression(2, 0.5);
    $regression->add([1.0, -1.0], -1.0);
    $regression->add([1.0, 1.0], 1.0);

    [$intercept, $slope] = $regression->solve();

    expect($intercept)->toEqualWithDelta(0.0, 1e-12)
        ->and($slope)->toEqualWithDelta(2 / 3, 1e-12);
});

test('an all-zero column is solvable with ridge and gets a zero coefficient', function (): void {
    $regression = new HybridRegression(3, 1e-4);

    foreach ([1.0, 2.0, 3.0] as $x) {
        $regression->add([1.0, $x, 0.0], $x);
    }

    expect($regression->solve()[2])->toBe(0.0);
});

test('solving leaves the accumulated rows untouched, so more rows can follow', function (): void {
    $regression = new HybridRegression(2, 1e-4);
    $fresh = new HybridRegression(2, 1e-4);
    $first = [[[1.0, -1.0], -0.5], [[1.0, 0.5], 1.2], [[1.0, 2.0], 2.9]];
    $second = [[[1.0, 3.0], 4.1], [[1.0, -2.0], -2.2]];

    foreach ($first as [$x, $y]) {
        $regression->add($x, $y);
        $fresh->add($x, $y);
    }

    $before = $regression->solve();

    expect($regression->solve())->toBe($before);

    foreach ($second as [$x, $y]) {
        $regression->add($x, $y);
        $fresh->add($x, $y);
    }

    expect($regression->count())->toBe(5)
        ->and($regression->solve())->toBe($fresh->solve());
});

test('fails on a wrong vector size and on an empty fit', function (): void {
    $regression = new HybridRegression(2, 1e-4);

    expect(fn () => $regression->add([1.0], 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $regression->solve())->toThrow(RuntimeException::class);
});

test('matches the research ols of backtest.js within 1e-12', function (): void {
    // Rows built with the same arithmetic as the reference script; the expected
    // coefficients are what comando-lechuga-research/value-forecast/backtest.js
    // `ols(X, y, 1e-4)` returns for them under Node (printed to 17 digits).
    $regression = new HybridRegression(4, 1e-4);

    for ($i = 0; $i < 12; $i++) {
        $a = (($i * 7) % 5 - 2) / 100;
        $b = $i % 3 === 0 ? 0.0 : (($i * 3) % 7) / 10;
        $c = $i % 4 === 0 ? 1.0 : 0.0;
        $y = max(-0.1, min(0.1, 0.002 + 0.8 * $a - 0.01 * $b + 0.03 * $c + (($i * 11) % 7 - 3) / 1000));

        $regression->add([1.0, (float) $a, (float) $b, $c], $y);
    }

    $coefficients = $regression->solve();

    expect($coefficients[0])->toEqualWithDelta(0.00033916425898959543, 1e-12)
        ->and($coefficients[1])->toEqualWithDelta(0.42566785145877845, 1e-12)
        ->and($coefficients[2])->toEqualWithDelta(-0.0011891246187759306, 1e-12)
        ->and($coefficients[3])->toEqualWithDelta(0.025805399156080079, 1e-12);
});
