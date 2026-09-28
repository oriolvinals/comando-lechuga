<?php

declare(strict_types=1);

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;

function consistencySeason(): Season
{
    return Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
}

test('names the standings rank, previous rank and squad value unambiguously', function (): void {
    $season = consistencySeason();
    SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1, 'last_position' => 3, 'value' => 150_000_000]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.rank', 1);
    $response->assertJsonPath('data.0.last_rank', 3);
    $response->assertJsonPath('data.0.squad_value', 150_000_000);
    $response->assertJsonMissingPath('data.0.position');
    $response->assertJsonMissingPath('data.0.value');
});

test('names the managers of an activity source_manager and target_manager', function (): void {
    $season = consistencySeason();
    $buyer = SeasonManager::factory()->create(['season_id' => $season->id]);
    $seller = SeasonManager::factory()->create(['season_id' => $season->id]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Buyout,
        'source_season_manager_id' => $buyer->id,
        'target_season_manager_id' => $seller->id,
    ]);

    $response = $this->getJson('/api/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.source_manager.id', $buyer->id);
    $response->assertJsonPath('data.0.target_manager.id', $seller->id);
    $response->assertJsonMissingPath('data.0.source_season_manager');
});

test('names a market listing\'s current value market_value and says the league sells it', function (): void {
    consistencySeason();
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create(['position' => PlayerPosition::Midfield])->id,
        'value' => 4_800_000,
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->getJson('/api/market');

    $response->assertOk();
    $response->assertJsonPath('data.0.market_value', 4_800_000);
    $response->assertJsonPath('data.0.seller', 'league');
    $response->assertJsonMissingPath('data.0.value');
});

test('names a fixture lineup\'s raw tactical slot pitch_position', function (): void {
    $season = consistencySeason();
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'position' => 'Center Midfielder']);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonPath('data.lineups.0.pitch_position', 'Center Midfielder');
    $response->assertJsonMissingPath('data.lineups.0.position');
});

test('returns average_points as a number', function (): void {
    consistencySeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'average_points' => 6.5]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.average_points', 6.5);
});

test('filters players by owner with the manager parameter', function (): void {
    $season = consistencySeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $owned->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson("/api/players?manager={$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $owned->id);
});

test('rejects an invalid players filter with a 422 naming the parameter', function (string $query, string $parameter): void {
    consistencySeason();

    $response = $this->getJson("/api/players?{$query}");

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors([$parameter]);
})->with([
    'unknown sort' => ['sort=bogus', 'sort'],
    'unknown direction' => ['direction=up', 'direction'],
    'coach position' => ['position=coach', 'position'],
    'out of league status' => ['status=out_of_league', 'status'],
    'non numeric team' => ['team=barcelona', 'team'],
    'renamed owner filter' => ['season_manager=4', 'season_manager'],
    'bad page' => ['page=0', 'page'],
]);

test('rejects an invalid activity type with a 422', function (): void {
    consistencySeason();

    $response = $this->getJson('/api/activity?type=signing,bogus');

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['type']);
});

test('tolerates spaces and empty items in a comma separated filter', function (): void {
    consistencySeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Midfield]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Striker]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Goalkeeper]);

    $response = $this->getJson('/api/players?position=midfield,%20striker,');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

test('ignores the query string on endpoints without filters', function (): void {
    consistencySeason();

    $this->getJson('/api/market?_=123')->assertOk();
    $this->getJson('/api/standings?nocache=1')->assertOk();
});
