<?php

use App\Models\Player;
use App\Models\Season;
use App\Models\ValueForecast;
use App\Models\ValueForecastFit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

test('a forecast casts its dates, amounts and reasons', function (): void {
    $forecast = ValueForecast::factory()->create([
        'reference_date' => '2026-09-29',
        'target_date' => '2026-09-30',
        'value' => 24_190_000,
        'predicted_value' => 25_443_846,
        'change_pct' => 5.2,
        'up_probability' => 0.99,
        'reasons' => [['kind' => 'inertia', 'label' => 'Inercia: cambio de hoy', 'impact_pct' => 5.65]],
    ])->fresh();

    expect($forecast->reference_date->toDateString())->toBe('2026-09-29')
        ->and($forecast->target_date->toDateString())->toBe('2026-09-30')
        ->and($forecast->predicted_value)->toBe(25_443_846)
        ->and($forecast->change_pct)->toBe(5.2)
        ->and($forecast->up_probability)->toBe(0.99)
        ->and($forecast->reasons[0]['kind'])->toBe('inertia');
});

test('one forecast per season, player and target date', function (): void {
    $season = Season::factory()->create();
    $player = Player::factory()->create();
    ValueForecast::factory()->create(['season_id' => $season->id, 'player_id' => $player->id, 'target_date' => '2026-09-30']);

    expect(fn () => ValueForecast::factory()->create(['season_id' => $season->id, 'player_id' => $player->id, 'target_date' => '2026-09-30']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a fit keeps its hash as an empty string by default and casts its json', function (): void {
    $season = Season::factory()->create();
    DB::table('value_forecast_fits')->insert([
        'season_id' => $season->id,
        'reference_date' => '2026-09-29',
        'coefficients' => '[0.1,-0.2]',
        'quantiles' => '{}',
        'metrics' => '{}',
    ]);

    $fit = ValueForecastFit::query()->sole();

    expect($fit->inputs_hash)->toBe('')
        ->and($fit->coefficients)->toBe([0.1, -0.2]);
});
