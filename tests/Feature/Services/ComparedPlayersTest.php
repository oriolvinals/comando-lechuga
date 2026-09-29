<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ComparedPlayers;
use App\Services\PlayerMarketMetrics;
use Illuminate\Support\Facades\DB;

function comparedSeason(): Season
{
    return Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
}

test('returns the players in the requested order with identity, market and performance figures', function (): void {
    $season = comparedSeason();
    $first = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 80, 'average_points' => 6.15, 'market_value' => 20_000_000]);
    $second = Player::factory()->create(['status' => PlayerStatus::Injured, 'points' => 40, 'market_value' => 10_000_000]);

    $players = app(ComparedPlayers::class)->forIds([$second->id, $first->id], $season);

    expect($players)->toHaveCount(2)
        ->and($players[0]['id'])->toBe($second->id)
        ->and($players[0]['status'])->toBe('injured')
        ->and($players[1]['name'])->toBe($first->nickname)
        ->and($players[1]['value'])->toBe(20_000_000)
        ->and($players[1]['points'])->toBe(80)
        ->and($players[1]['average_points'])->toBe(6.15)
        ->and($players[1]['points_per_million']['value'])->toBe(4.0)
        ->and($players[1]['team']->id)->toBe($first->team_id);
});

test('the market history is the last 31 snapshots as date-value pairs, and the 30-day trend matches the ficha', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 14_000_000]);
    foreach (range(40, 0) as $daysAgo) {
        PlayerMarket::factory()->create([
            'player_id' => $player->id,
            'date' => now()->subDays($daysAgo)->toDateString(),
            'value' => 10_000_000 + (40 - $daysAgo) * 100_000,
        ]);
    }

    $compared = app(ComparedPlayers::class)->forIds([$player->id], $season)[0];
    $history = PlayerMarket::query()->where('player_id', $player->id)->orderBy('date')->get();

    expect($compared['market_history'])->toHaveCount(31)
        ->and($compared['market_history'][0])->toBe([now()->subDays(30)->toDateString(), 11_000_000])
        ->and($compared['market_history'][30])->toBe([now()->toDateString(), 14_000_000])
        ->and($compared['value_trend_30d'])->toBe(app(PlayerMarketMetrics::class)->valueTrend(14_000_000, $history));
});

test('a player without history, fixtures or start data gets empty and null figures', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 0, 'points' => 0]);

    $compared = app(ComparedPlayers::class)->forIds([$player->id], $season)[0];

    expect($compared['market_history'])->toBe([])
        ->and($compared['value_trend_30d'])->toBeNull()
        ->and($compared['points_per_million'])->toBeNull()
        ->and($compared['scores'])->toBe([])
        ->and($compared['next_fixtures'])->toBe([null, null, null])
        ->and($compared['next_start'])->toBeNull()
        ->and($compared['owner'])->toBeNull()
        ->and($compared['clause'])->toBeNull()
        ->and($compared['listing'])->toBeNull();
});

test('scores cover the season in jornada order, with minutes, rival side and DAZN only official once published', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $published = Fixture::factory()->daznPublished()->create([
        'season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Finished,
        'team_local_id' => $player->team_id,
    ]);
    $live = Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 3, 'state' => FixtureState::SecondHalf,
        'team_guest_id' => $player->team_id,
    ]);
    $otherSeason = Fixture::factory()->create(['season_id' => Season::factory()->create()->id, 'week_number' => 1]);

    FixtureLineup::factory()->withDaznEstimate(points: 2)->create([
        'player_id' => $player->id, 'fixture_id' => $live->id, 'team_id' => $player->team_id,
        'starter' => false, 'fantasy_points' => 1, 'fantasy_stats' => ['mins_played' => [25, 1]],
    ]);
    FixtureLineup::factory()->withDaznEstimate(points: 1)->create([
        'player_id' => $player->id, 'fixture_id' => $published->id, 'team_id' => $player->team_id,
        'starter' => true, 'fantasy_points' => 9, 'fantasy_stats' => ['mins_played' => [90, 2], 'marca_points' => [3, 4]],
    ]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $otherSeason->id]);

    $scores = app(ComparedPlayers::class)->forIds([$player->id], $season)[0]['scores'];

    expect($scores)->toHaveCount(2)
        ->and($scores[0]['week_number'])->toBe(2)
        ->and($scores[0]['is_home'])->toBeTrue()
        ->and($scores[0]['opponent']->id)->toBe($published->team_guest_id)
        ->and($scores[0]['minutes'])->toBe(90)
        ->and($scores[0]['starter'])->toBeTrue()
        ->and($scores[0]['points'])->toBe(9)
        ->and($scores[0]['dazn_points'])->toBe(4)
        ->and($scores[1]['week_number'])->toBe(3)
        ->and($scores[1]['is_home'])->toBeFalse()
        ->and($scores[1]['fixture_state'])->toBe('second_half')
        ->and($scores[1]['minutes'])->toBe(25)
        ->and($scores[1]['dazn_points'])->toBeNull()
        ->and($scores[1]['dazn_estimate'])->toBe(2);
});

test('an owned player carries the owner, the clause and the owner purchase; a listed one the listing', function (): void {
    $season = comparedSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'primary_color' => '#00ff00']);
    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $listed = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $manager->id, 'player_id' => $owned->id,
        'buyout_clause' => 15_000_000, 'buyout_clause_locked_until' => now()->addDay(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id, 'player_id' => $owned->id, 'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => SeasonManager::factory()->create(['season_id' => $season->id])->id,
        'amount' => 1_000_000, 'occurred_at' => now()->subWeeks(2),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id, 'player_id' => $owned->id, 'type' => SeasonActivityType::Buyout,
        'source_season_manager_id' => $manager->id, 'amount' => 8_000_000, 'occurred_at' => now()->subWeek(),
    ]);
    MarketPlayer::factory()->create(['player_id' => $listed->id, 'sale_price' => 5_000_000, 'bids' => 2]);

    [$ownedShape, $listedShape] = app(ComparedPlayers::class)->forIds([$owned->id, $listed->id], $season);

    expect($ownedShape['owner'])->toMatchArray(['id' => $manager->id, 'name' => $manager->name, 'color' => '#00ff00'])
        ->and($ownedShape['clause']['amount'])->toBe(15_000_000)
        ->and($ownedShape['clause']['is_locked'])->toBeTrue()
        ->and($ownedShape['clause']['purchase'])->toMatchArray(['amount' => 8_000_000, 'type' => 'buyout'])
        ->and($ownedShape['listing'])->toBeNull()
        ->and($listedShape['owner'])->toBeNull()
        ->and($listedShape['listing'])->toMatchArray(['sale_price' => 5_000_000, 'bids' => 2, 'seller' => 'league']);
});

test('the next fixtures carry the 0-10 difficulty with the variant of the player position', function (): void {
    $season = comparedSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => 'striker']);
    Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 2, 'date' => now()->addDays(2),
        'state' => FixtureState::Scheduled, 'team_local_id' => $player->team_id,
    ]);

    $slot = app(ComparedPlayers::class)->forIds([$player->id], $season)[0]['next_fixtures'][0];

    expect($slot['week_number'])->toBe(2)
        ->and($slot['is_home'])->toBeTrue()
        ->and($slot)->toHaveKeys(['date', 'difficulty', 'difficulty_variant', 'difficulty_components', 'rival_position']);
});

test('runs the same number of queries for one player as for three', function (): void {
    $season = comparedSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $players = Player::factory()->count(3)->create(['status' => PlayerStatus::Ok]);
    foreach ($players as $player) {
        ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
        PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString()]);
        $fixture = Fixture::factory()->create(['season_id' => $season->id, 'state' => FixtureState::Finished, 'team_local_id' => $player->team_id]);
        FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'team_id' => $player->team_id]);
    }
    $service = app(ComparedPlayers::class);

    DB::enableQueryLog();
    $service->forIds([$players[0]->id], $season);
    $single = count(DB::getQueryLog());
    DB::flushQueryLog();
    $service->forIds($players->pluck('id')->all(), $season);
    $triple = count(DB::getQueryLog());

    expect($triple)->toBe($single);
});
