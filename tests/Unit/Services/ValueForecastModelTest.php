<?php

use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use App\Services\ValueForecast\ValueForecastModel;
use App\Services\ValueForecast\ValueForecastParameters;
use App\Services\ValueForecast\ValueForecastRow;

/**
 * @param  array<int, float>  $weights  feature index → coefficient
 */
function forecastModel(array $weights = []): ValueForecastModel
{
    $coefficients = array_fill(0, Vector::SIZE, 0.0);

    foreach ($weights as $index => $weight) {
        $coefficients[$index] = $weight;
    }

    return new ValueForecastModel($coefficients, new ValueForecastParameters);
}

test('with zero coefficients tomorrow repeats today\'s change', function (): void {
    $prediction = forecastModel()->predict(forecastRow(['changeToday' => 0.02]));

    expect($prediction->change)->toBe(0.02)
        ->and($prediction->predictedValue())->toBe(10_200_000)
        ->and($prediction->direction(0.005))->toBe('up')
        ->and($prediction->reasons)->toBe([['kind' => 'inertia', 'label' => 'Inercia: cambio de hoy', 'impact_pct' => 2.0]]);
});

test('never forecasts below the daily floor and says so', function (): void {
    $prediction = forecastModel([Vector::CONSTANT => -0.05])->predict(forecastRow(['changeToday' => -0.02]));

    expect($prediction->change)->toBe(-0.0346)
        ->and($prediction->direction(0.005))->toBe('down')
        ->and(array_column($prediction->reasons, 'kind'))->toContain('floor');
});

test('names the floor it was held at', function (): void {
    $model = new ValueForecastModel([-0.05, ...array_fill(0, Vector::SIZE - 1, 0.0)], new ValueForecastParameters(floor: -0.025));
    $reasons = $model->predict(forecastRow(['changeToday' => -0.02]))->reasons;

    expect($reasons[array_search('floor', array_column($reasons, 'kind'), true)]['label'])->toBe('Suelo diario −2,50 %');
});

test('without residuals the interval is the forecast itself and P(up) is a coin toss', function (): void {
    $model = forecastModel();
    $prediction = $model->predict(forecastRow(['changeToday' => 0.02]));

    expect($model->hasResiduals())->toBeFalse()
        ->and($model->withResiduals([forecastRow(['nextValue' => 10_100_000])])->hasResiduals())->toBeTrue()
        ->and([$prediction->lowChange, $prediction->highChange, $prediction->upProbability])->toBe([0.02, 0.02, 0.5]);
});

test('adds a great match yesterday as the top reason with its date and points', function (): void {
    $row = forecastRow([
        'referenceDate' => '2026-09-29',
        'changeToday' => -0.01,
        'matchYesterday' => ['team' => true, 'played' => true, 'points' => 12],
    ]);

    $prediction = forecastModel([Vector::YESTERDAY + 4 => 0.04, Vector::MARKET => 0.5])->predict($row);

    expect($prediction->change)->toEqualWithDelta(-0.01 + 0.04 + 0.5 * -0.007, 1e-12)
        ->and($prediction->reasons[0]['kind'])->toBe('inertia')
        ->and($prediction->reasons[1])->toBe(['kind' => 'match_yesterday', 'label' => 'Partido del 28/09 · 12 pts', 'impact_pct' => 4.0])
        ->and($prediction->reasons[2])->toBe(['kind' => 'market', 'label' => 'Mercado general', 'impact_pct' => -0.35]);
});

test('keeps at most three reasons besides the inertia, dropping those under 0,1 pp', function (): void {
    $prediction = forecastModel([
        Vector::CONSTANT => 0.011,
        Vector::MARKET => 1.0,
        Vector::CHANGE_YESTERDAY => -1.0,
        Vector::BREAK_OVER_7_DAYS => 0.02,
        Vector::MATCH_TOMORROW => 0.0005,
    ])->predict(forecastRow(['daysToNextMatch' => 12]));

    expect(array_column($prediction->reasons, 'kind'))->toBe(['inertia', 'calendar', 'baseline', 'streak'])
        ->and($prediction->reasons[1]['label'])->toBe('Próximo partido en 12 días')
        ->and($prediction->reasons[3]['label'])->toBe('Freno de racha');
});

test('the interval and P(up) come from the residuals of the rows\' group', function (): void {
    $model = forecastModel();
    // Residuals of "no match yesterday" rows: actual − 0,02 persistence → −0,01 … +0,03 in 0,01 steps.
    $history = array_map(
        fn (float $actual): mixed => forecastRow(['changeToday' => 0.02, 'nextValue' => (int) round(10_000_000 * (1 + $actual))]),
        [0.01, 0.02, 0.03, 0.04, 0.05],
    );

    $prediction = $model->withResiduals($history)->predict(forecastRow(['changeToday' => 0.005]));

    // Sorted residuals [−0,01, 0, 0,01, 0,02, 0,03]: q10 = index floor(0,1·4) = 0 → −0,01; q90 = index 3 → 0,02.
    expect($prediction->lowChange)->toEqualWithDelta(-0.005, 1e-9)
        ->and($prediction->highChange)->toEqualWithDelta(0.025, 1e-9)
        ->and($prediction->low())->toBe(9_950_000)
        ->and($prediction->high())->toBe(10_250_000)
        ->and($prediction->upProbability)->toBe(0.8)
        ->and($prediction->direction(0.005))->toBe('stable');
});

test('a row whose team played yesterday uses that group, or the other one while it is empty', function (): void {
    $model = forecastModel()->withResiduals([forecastRow(['changeToday' => 0.0, 'nextValue' => 10_100_000])]);
    $afterMatch = forecastRow(['changeToday' => 0.0, 'matchYesterday' => ['team' => true, 'played' => false, 'points' => 0]]);

    expect($model->predict($afterMatch)->highChange)->toEqualWithDelta(0.01, 1e-9)
        ->and($model->quantiles()['match_yesterday']['size'])->toBe(0)
        ->and($model->quantiles()['no_match_yesterday']['size'])->toBe(1);
});

/**
 * A deterministic, varied row for the parity test (odd rows: his team did not play yesterday).
 */
function parityForecastRow(int $i): ValueForecastRow
{
    $value = 2_000_000 + (($i * 7_919) % 23) * 1_000_000;
    $played = $i % 3 !== 0;

    return forecastRow([
        'value' => $value,
        'changeToday' => (($i * 7) % 11 - 5) / 100,
        'changeYesterday' => (($i * 5) % 9 - 4) / 100,
        'changeBefore' => (($i * 3) % 7 - 3) / 100,
        'marketChange' => (($i * 11) % 5 - 2) / 1000,
        'matchYesterday' => ['team' => $i % 2 === 0, 'played' => $played, 'points' => $played ? ($i * 5) % 17 - 2 : 0],
        'matchToday' => ['team' => $i % 5 === 1, 'played' => true, 'points' => ($i * 3) % 13],
        'daysToNextMatch' => 1 + ($i * 4) % 12,
        'averagePoints' => (($i * 3) % 8) + 0.5,
        'nextValue' => (int) round($value * (1 + (($i * 13) % 17 - 8) / 200)),
    ]);
}

test('matches the research hybrid prediction, interval and P(up) of backtest.js within 1e-12', function (): void {
    // Rows 0–39 are the residual pool and rows 40–45 are forecast. The expected
    // [pred, lo, hi, pUp] are what the HYB lines of comando-lechuga-research/
    // value-forecast/backtest.js (pool split by `miLag1.team`, `q2`, `up2`)
    // return under Node for these rows' `Vector::of`, p0 and y (17 digits).
    $coefficients = array_map(fn (int $k): float => (($k * 13) % 9 - 4) / 1000, range(0, Vector::SIZE - 1));
    $model = (new ValueForecastModel($coefficients, new ValueForecastParameters))
        ->withResiduals(array_map(parityForecastRow(...), range(0, 39)));

    $expected = [
        [-0.0047665882494628995, -0.034599999999999999, 0.026960757770566373, 0.45000000000000001],
        [-0.034599999999999999, -0.034599999999999999, 0.014635647061243200, 0.25000000000000000],
        [0.026530940912877974, -0.017529435924072790, 0.058258286932907244, 0.65000000000000002],
        [-0.014426784605116504, -0.034599999999999999, 0.034808862456126695, 0.34999999999999998],
        [-0.034599999999999999, -0.034599999999999999, -0.0028726539799707254, 0.10000000000000001],
        [0.014473287934792146, -0.034599999999999999, 0.063708934996035346, 0.69999999999999996],
    ];

    foreach ($expected as $offset => [$change, $low, $high, $upProbability]) {
        $prediction = $model->predict(parityForecastRow(40 + $offset));

        expect([$prediction->change, $prediction->lowChange, $prediction->highChange, $prediction->upProbability])
            ->toEqualWithDelta([$change, $low, $high, $upProbability], 1e-12);
    }

    expect($model->quantiles()['match_yesterday']['size'])->toBe(20)
        ->and($model->quantiles()['no_match_yesterday']['size'])->toBe(20);
});
