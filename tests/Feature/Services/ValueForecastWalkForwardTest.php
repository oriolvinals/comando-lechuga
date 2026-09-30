<?php

use App\Models\Season;
use App\Services\ValueForecast\ValueForecastDay;
use App\Services\ValueForecast\ValueForecastParameters;
use App\Services\ValueForecast\ValueForecastWalkForward;

beforeEach(function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
});

test('fits only on outcomes known by the reference date and predicts that day\'s rows', function (): void {
    foreach (range(1, 4) as $index) {
        forecastPlayer($this->season, array_map(fn (int $day): int => 10_000_000 + $index * $day * 50_000, range(0, 19)), '2026-09-20');
    }

    $days = iterator_to_array(app(ValueForecastWalkForward::class)->days($this->season, '2026-09-15', '2026-09-19'), false);

    expect($days)->not->toBeEmpty()
        ->and(array_map(fn (ValueForecastDay $day): string => $day->referenceDate, $days))->toBe(['2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19'])
        ->and($days[0]->targetDate())->toBe('2026-09-16')
        ->and($days[0]->predictions)->toHaveCount(4)
        // Rows from 2026-09-04 (T−3 = 09-01) with targets ≤ 09-15: T = 09-04 … 09-14 → 11 days × 4 players.
        ->and($days[0]->trainingRows)->toBe(44)
        ->and($days[1]->trainingRows)->toBe(48);
});

test('yields nothing while there are too few training rows', function (): void {
    forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000], '2026-09-20');

    expect(iterator_to_array(app(ValueForecastWalkForward::class)->days($this->season, '2026-09-19', '2026-09-19'), false))->toBe([]);
});

test('takes the interval from the residuals of the last days only and skips a day without any', function (): void {
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10, residualWindowDays: 3));

    foreach (range(1, 3) as $index) {
        forecastPlayer($this->season, array_map(fn (int $day): int => 10_000_000 + $index * $day * 50_000, range(0, 9)), '2026-09-10');
    }

    forecastPlayer($this->season, [9_000_000, 9_100_000, 9_200_000, 9_300_000], '2026-09-19');

    // 18 known outcomes (targets 09-05 … 09-10) but none dated 09-17 … 09-19: no interval to give, so no forecast.
    expect(iterator_to_array(app(ValueForecastWalkForward::class)->days($this->season, '2026-09-19', '2026-09-19'), false))->toBe([]);

    $days = iterator_to_array(app(ValueForecastWalkForward::class)->days($this->season, '2026-09-09', '2026-09-09'), false);

    // Targets 09-07 … 09-09 → 3 days × 3 players.
    expect($days)->toHaveCount(1)
        ->and($days[0]->model->quantiles()['no_match_yesterday']['size'])->toBe(9);
});

test('lists predicted values by player and reference date', function (): void {
    $player = forecastPlayer($this->season, array_map(fn (int $day): int => 10_000_000 + $day * 100_000, range(0, 19)), '2026-09-20');
    foreach (range(1, 3) as $index) {
        forecastPlayer($this->season, array_map(fn (int $day): int => 8_000_000 - $index * $day * 20_000, range(0, 19)), '2026-09-20');
    }

    $values = app(ValueForecastWalkForward::class)->predictedValues($this->season, '2026-09-18', '2026-09-19');

    expect(array_keys($values[$player->id]))->toBe(['2026-09-18', '2026-09-19'])
        ->and($values[$player->id]['2026-09-19'])->toBeInt()->toBeGreaterThan(11_800_000);
});
