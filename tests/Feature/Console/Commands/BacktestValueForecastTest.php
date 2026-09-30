<?php

use App\Console\Commands\BacktestValueForecast;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\ValueForecast;
use App\Services\ValueForecast\ValueForecastParameters;

test('replays the forecast day by day and reports each predictor, read-only', function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
    foreach (range(1, 4) as $index) {
        forecastPlayer($season, array_map(fn (int $day): int => 10_000_000 + ($index - 2) * $day * 40_000, range(0, 19)), '2026-09-20');
    }

    $this->artisan(BacktestValueForecast::class, ['--from' => '2026-09-16', '--to' => '2026-09-20'])
        ->expectsOutputToContain('Persistencia')
        ->expectsOutputToContain('Momentum puja')
        ->expectsOutputToContain('Híbrido')
        ->expectsOutputToContain('Calibración de P(sube)')
        ->assertSuccessful();

    expect(ValueForecast::query()->count())->toBe(0)
        ->and(PlayerMarket::query()->count())->toBe(80);
});

test('fails clearly without market history', function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);

    $this->artisan(BacktestValueForecast::class)
        ->expectsOutputToContain('No hay histórico de mercado')
        ->assertFailed();
});
