<?php

declare(strict_types=1);

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\ValueForecast\ValueForecastRow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Saloon\Config;
use Tests\TestCase;

// Any Saloon HTTP call made during the test suite without an explicit MockClient bound
// throws loudly instead of silently reaching the network. This catches tests that create
// models (e.g. Player::factory(), which assigns a random fantasy_id) and then, via some
// code path, end up calling a real connector — which would otherwise hit production APIs
// non-deterministically and have its failure silently swallowed by a catch block.
Config::preventStrayRequests();

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something(): void
{
    // ..
}

/**
 * A value forecast row with sensible defaults: a 10 M€ player rising 2 %
 * today, no matches around, next match in 5 days, 10,3 M€ tomorrow.
 *
 * @param  array<string, mixed>  $overrides
 */
function forecastRow(array $overrides = []): ValueForecastRow
{
    return new ValueForecastRow(...[
        'playerId' => 1,
        'referenceDate' => '2026-09-29',
        'targetDate' => '2026-09-30',
        'value' => 10_000_000,
        'changeToday' => 0.02,
        'changeYesterday' => 0.01,
        'changeBefore' => 0.005,
        'marketChange' => -0.007,
        'matchYesterday' => ['team' => false, 'played' => false, 'points' => 0],
        'matchToday' => ['team' => false, 'played' => false, 'points' => 0],
        'matchBefore' => ['team' => false, 'played' => false, 'points' => 0],
        'daysToNextMatch' => 5,
        'averagePoints' => 4.0,
        'nextValue' => 10_300_000,
        ...$overrides,
    ]);
}

/**
 * A season player with one market value per day ending on `$lastDate`.
 *
 * @param  list<int>  $values  oldest first
 * @param  array<string, mixed>  $attributes
 */
function forecastPlayer(Season $season, array $values, string $lastDate, array $attributes = []): Player
{
    $team = isset($attributes['team_id']) ? Team::query()->findOrFail($attributes['team_id']) : Team::factory()->create();
    $season->teams()->syncWithoutDetaching([$team->id]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, ...$attributes]);
    $last = CarbonImmutable::parse($lastDate);

    foreach (array_values($values) as $index => $value) {
        PlayerMarket::factory()->create([
            'player_id' => $player->id,
            'date' => $last->subDays(count($values) - 1 - $index)->toDateString(),
            'value' => $value,
        ]);
    }

    return $player;
}
