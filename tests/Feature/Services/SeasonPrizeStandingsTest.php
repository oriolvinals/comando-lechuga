<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\SeasonPrizeStandings;
use Illuminate\Support\Facades\Cache;

function currentPrizeSeason(int $currentWeek): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
        'current_week' => $currentWeek, 'total_weeks' => 38,
    ]);
}

test('builds the ten prizes in page order with every manager ranked', function (): void {
    $season = currentPrizeSeason(2);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);
    [$gau, $cid] = SeasonManager::factory()->count(2)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    ManagerLineup::factory()->create(['season_manager_id' => $gau->id, 'week_number' => 1, 'points' => 71]);
    ManagerLineup::factory()->create(['season_manager_id' => $cid->id, 'week_number' => 1, 'points' => 71]);

    $prizes = app(SeasonPrizeStandings::class)->forSeason($season)['prizes'];
    $noche = $prizes[0];

    expect(array_column($prizes, 'key'))->toHaveCount(10)
        ->and($noche['key'])->toBe('best_night')
        ->and($noche['leaders'])->toBe([$gau->id, $cid->id])
        ->and($noche['shares'])->toBe([$gau->id => 5.0, $cid->id => 5.0])
        ->and(array_column($noche['rows'], 'place'))->toBe([1, 1])
        ->and($prizes[9]['key'])->toBe('worst_night')
        ->and($prizes[9]['leaders'])->toBe([$gau->id, $cid->id]);
});

test('before any finished jornada nobody leads anything', function (): void {
    $season = currentPrizeSeason(1);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::FirstHalf]);
    SeasonManager::factory()->count(3)->create(['season_id' => $season->id]);

    $prizes = app(SeasonPrizeStandings::class)->forSeason($season)['prizes'];

    expect(array_merge(...array_column($prizes, 'leaders')))->toBe([]);
});

test('every tied most-owned player gives the prize to his own winner', function (): void {
    $season = currentPrizeSeason(3);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => '2026-08-29 19:00:00', 'state' => FixtureState::Scheduled]);

    [$dubi, $cid, $cruza, $gau] = SeasonManager::factory()->count(4)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    [$first, $second] = Player::factory()->count(2)->create()->all();

    $move = fn (Player $player, SeasonActivityType $type, SeasonManager $source, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'player_id' => $player->id,
        'source_season_manager_id' => $source->id, 'target_season_manager_id' => null, 'occurred_at' => $at,
    ]);

    $move($first, SeasonActivityType::Sale, $dubi, '2026-08-10 10:00:00');
    $move($first, SeasonActivityType::Signing, $cid, '2026-08-11 20:00:00');
    $move($second, SeasonActivityType::Sale, $cruza, '2026-08-10 10:00:00');
    $move($second, SeasonActivityType::Signing, $gau, '2026-08-12 20:00:00');
    $move($second, SeasonActivityType::Sale, $gau, '2026-08-20 10:00:00');

    $standings = app(SeasonPrizeStandings::class)->forSeason($season);
    $mostOwned = collect($standings['prizes'])->firstWhere('key', 'most_owned_player');
    $places = collect($mostOwned['rows'])->pluck('place', 'season_manager_id');

    expect($mostOwned['leaders'])->toBe([$cid->id, $gau->id])
        ->and($mostOwned['shares'])->toBe([$cid->id => 2.5, $gau->id => 2.5])
        ->and($places[$cid->id])->toBe(1)
        ->and($places[$gau->id])->toBe(1)
        ->and($mostOwned['candidates'])->toHaveCount(2)
        ->and($standings['players'])->toHaveKeys([$first->id, $second->id]);
});

/**
 * A season with one finished jornada and one row of every input the prizes read.
 *
 * @return array{season: Season, manager: SeasonManager, rival: SeasonManager, player: Player, fixture: Fixture, lineup: ManagerLineup, score: FixtureLineup, market: PlayerMarket}
 */
function cachedPrizeSeason(): array
{
    $season = currentPrizeSeason(2);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::Scheduled]);
    [$manager, $rival] = SeasonManager::factory()->count(2)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    $player = Player::factory()->create();
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 60]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id]);
    $score = FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'fantasy_points' => 8]);
    $market = PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-08-10', 'value' => 10_000_000]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
    Activity::factory()->create([
        'season_id' => $season->id, 'type' => SeasonActivityType::Signing, 'player_id' => $player->id,
        'source_season_manager_id' => $manager->id, 'target_season_manager_id' => null, 'amount' => 12_000_000, 'occurred_at' => '2026-08-11 10:00:00',
    ]);

    return compact('season', 'manager', 'rival', 'player', 'fixture', 'lineup', 'score', 'market');
}

/**
 * Plants a marker under the season's current cache key and reports whether
 * a fresh request (new scoped clock) is still served from it.
 *
 * @param  Closure(): void  $change
 */
function servedFromCacheAfter(Season $season, Closure $change): bool
{
    Cache::put(app(SeasonPrizeStandings::class)->cacheKey($season), ['prizes' => [], 'players' => ['cached']], 600);

    $change();
    app()->forgetScopedInstances();

    return app(SeasonPrizeStandings::class)->forSeason($season->fresh())['players'] === ['cached'];
}

test('serves the cached standings while none of their data changed', function (): void {
    $data = cachedPrizeSeason();

    expect(servedFromCacheAfter($data['season'], fn () => SeasonManager::query()->update(['total_points' => 99, 'live_points' => 5])))->toBeTrue();
});

test('rebuilds the standings when any of their data changes', function (Closure $change): void {
    $data = cachedPrizeSeason();

    expect(servedFromCacheAfter($data['season'], fn () => $change($data)))->toBeFalse();
})->with([
    'a new activity' => fn (array $data) => Activity::factory()->create([
        'season_id' => $data['season']->id, 'type' => SeasonActivityType::Buyout, 'player_id' => $data['player']->id,
        'source_season_manager_id' => $data['rival']->id, 'target_season_manager_id' => $data['manager']->id, 'amount' => 20_000_000,
    ]),
    'a lineup\'s points' => fn (array $data) => $data['lineup']->update(['points' => 61]),
    'a lined-up player' => fn (array $data) => ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $data['lineup']->id]),
    'a player\'s fixture points' => fn (array $data) => $data['score']->update(['fantasy_points' => 9]),
    'a market value' => fn (array $data) => $data['market']->update(['value' => 11_000_000]),
    'a squad' => fn (array $data) => ManagerPlayer::query()->update(['season_manager_id' => $data['rival']->id]),
    'a manager\'s position' => fn (array $data) => $data['manager']->update(['position' => 2]),
    'a finished jornada' => fn (array $data) => Fixture::query()->where('week_number', 2)->update(['state' => FixtureState::Finished]),
    'a lineup lock' => fn (array $data) => $data['fixture']->update(['date' => '2026-08-15 21:00:00']),
]);
