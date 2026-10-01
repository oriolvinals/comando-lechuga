<?php

use App\Console\Commands\ForecastValues;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Season;
use App\Models\Team;
use App\Models\ValueForecast;
use App\Models\ValueForecastFit;
use App\Services\ValueForecast\ValueForecastParameters;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->travelTo('2026-09-20 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
    $this->players = collect(range(1, 4))->map(fn (int $index) => forecastPlayer(
        $this->season,
        array_map(fn (int $day): int => 10_000_000 + ($index - 2) * $day * 40_000, range(0, 19)),
        '2026-09-20',
    ));
});

test('writes tomorrow\'s forecast of every league player and the fit of the day', function (): void {
    $this->artisan(ForecastValues::class)->assertSuccessful();

    $forecast = ValueForecast::query()->where('player_id', $this->players[0]->id)->sole();
    $fit = ValueForecastFit::query()->sole();

    expect(ValueForecast::query()->count())->toBe(4)
        ->and($forecast->reference_date->toDateString())->toBe('2026-09-20')
        ->and($forecast->target_date->toDateString())->toBe('2026-09-21')
        ->and($forecast->value)->toBe(10_000_000 - 19 * 40_000)
        ->and($forecast->predicted_value)->toBeGreaterThan(0)
        ->and($forecast->low)->toBeLessThanOrEqual($forecast->predicted_value)
        ->and($forecast->high)->toBeGreaterThanOrEqual($forecast->predicted_value)
        ->and($forecast->reasons[0]['kind'])->toBe('inertia')
        ->and($fit->reference_date->toDateString())->toBe('2026-09-20')
        ->and($fit->inputs_hash)->not->toBe('')
        ->and($fit->coefficients)->toHaveCount(30);
});

test('does nothing when the inputs did not change, and refits when a match finishes', function (): void {
    $this->artisan(ForecastValues::class)->assertSuccessful();
    $firstWrite = ValueForecast::query()->max('updated_at');
    $this->travel(15)->minutes();

    $this->artisan(ForecastValues::class)->expectsOutputToContain('Sin cambios')->assertSuccessful();
    expect(ValueForecast::query()->max('updated_at'))->toBe($firstWrite);
    $hashBefore = ValueForecastFit::query()->sole()->inputs_hash;

    $player = $this->players[0];
    $fixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'team_local_id' => $player->team_id,
        'team_guest_id' => Team::factory()->create()->id,
        'date' => '2026-09-20 16:00:00',
        'state' => FixtureState::Finished,
    ]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $player->team_id, 'fantasy_points' => 14, 'fantasy_stats' => ['mins_played' => [90, 2]]]);

    $this->artisan(ForecastValues::class)->assertSuccessful();

    expect(ValueForecastFit::query()->sole()->inputs_hash)->not->toBe($hashBefore)
        ->and(ValueForecast::query()->max('updated_at'))->not->toBe($firstWrite);
});

test('refits when a finished match gets its points or minutes late', function (): void {
    $player = $this->players[0];
    $fixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'team_local_id' => $player->team_id,
        'team_guest_id' => Team::factory()->create()->id,
        'date' => '2026-09-20 16:00:00',
        'state' => FixtureState::Finished,
    ]);
    $lineup = FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $player->team_id, 'fantasy_points' => null, 'fantasy_stats' => []]);
    $this->artisan(ForecastValues::class)->assertSuccessful();
    $hashes = [ValueForecastFit::query()->sole()->inputs_hash];

    $lineup->update(['fantasy_stats' => ['mins_played' => [90, 2]]]);
    $this->artisan(ForecastValues::class)->doesntExpectOutputToContain('Sin cambios')->assertSuccessful();
    $hashes[] = ValueForecastFit::query()->sole()->inputs_hash;

    $lineup->update(['fantasy_points' => 14]);
    $this->artisan(ForecastValues::class)->doesntExpectOutputToContain('Sin cambios')->assertSuccessful();
    $hashes[] = ValueForecastFit::query()->sole()->inputs_hash;

    expect(array_unique($hashes))->toHaveCount(3);
});

test('skips out-of-league players and players of other teams, and drops their stale rows', function (): void {
    $this->artisan(ForecastValues::class)->assertSuccessful();
    $this->players[1]->update(['status' => PlayerStatus::OutOfLeague]);

    $this->artisan(ForecastValues::class, ['--force' => true])->assertSuccessful();

    expect(ValueForecast::query()->pluck('player_id')->all())->not->toContain($this->players[1]->id)
        ->and(ValueForecast::query()->count())->toBe(3);
});

test('says so and writes nothing without enough history', function (): void {
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 100_000));

    $this->artisan(ForecastValues::class)->expectsOutputToContain('Sin datos suficientes')->assertSuccessful();

    expect(ValueForecast::query()->count())->toBe(0);
});

test('says so and writes nothing outside a season', function (): void {
    $this->travelTo('2027-07-01 12:00:00');

    $this->artisan(ForecastValues::class)->expectsOutputToContain('No hay temporada activa')->assertSuccessful();

    expect(ValueForecast::query()->count())->toBe(0);
});

test('skips quietly while another run holds the lock', function (): void {
    $lock = Cache::lock(ForecastValues::LOCK, 60);
    $lock->get();

    $this->artisan(ForecastValues::class)
        ->expectsOutput('Otra previsión en curso.')
        ->assertSuccessful();

    expect(ValueForecast::query()->exists())->toBeFalse();

    $lock->release();

    $this->artisan(ForecastValues::class)->assertSuccessful();

    expect(ValueForecast::query()->exists())->toBeTrue();
});
