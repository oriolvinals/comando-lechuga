<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

test('returns the player info fields', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $player = Player::factory()->create([
        'fantasy_id' => 123,
        'nickname' => 'Pedri',
        'status' => PlayerStatus::Ok,
        'position' => PlayerPosition::Midfield,
        'team_id' => $team->id,
        'points' => 120,
        'market_value' => 45_000_000,
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $player->id);
    $response->assertJsonPath('data.url', route('players.show', $player->id));
    $response->assertJsonPath('data.nickname', 'Pedri');
    $response->assertJsonPath('data.status', 'ok');
    $response->assertJsonPath('data.position', 'midfield');
    $response->assertJsonPath('data.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.points', 120);
    $response->assertJsonPath('data.market_value', 45_000_000);
});

test('returns 404 for a player without a fantasy_id', function (): void {
    $player = Player::factory()->create();
    $player->fantasy_id = null;
    $player->save();

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertNotFound();
});

test('returns 404 for a player that does not exist', function (): void {
    $response = $this->getJson('/api/players/999999');

    $response->assertNotFound();
});

test('returns the owner manager when the player is owned', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Comando Lechuga']);
    $player = Player::factory()->create();
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.owner_manager.id', $manager->id);
    $response->assertJsonPath('data.owner_manager.name', 'Comando Lechuga');
});

test('has a null owner_manager for a free player', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.owner_manager', null);
});

test('returns the current market listing when the player is up for sale', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    MarketPlayer::factory()->create([
        'player_id' => $player->id,
        'sale_price' => 5_000_000,
        'value' => 4_800_000,
        'bids' => 2,
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.market_listing.sale_price', 5_000_000);
    $response->assertJsonPath('data.market_listing.value', 4_800_000);
    $response->assertJsonPath('data.market_listing.bids', 2);
});

test('has a null market_listing when the player is not for sale', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.market_listing', null);
});

test('returns the market value history ordered by date', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-08-20', 'value' => 4_500_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-08-19', 'value' => 4_400_000]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonCount(2, 'data.market_history');
    $response->assertJsonPath('data.market_history.0.date', '2026-08-19');
    $response->assertJsonPath('data.market_history.0.value', 4_400_000);
    $response->assertJsonPath('data.market_history.1.date', '2026-08-20');
});

test('returns scores for played fixtures with the fielding manager', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $opponent = Team::factory()->create(['main_name' => 'Rival FC']);
    $player = Player::factory()->create(['team_id' => $team->id]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Comando Lechuga']);

    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'state' => FixtureState::Finished,
        'team_local_id' => $team->id,
        'team_guest_id' => $opponent->id,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $team->id,
        'fantasy_points' => 9,
        'fantasy_stats' => ['goals' => [1, 0]],
    ]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.scores');
    $response->assertJsonPath('data.scores.0.week_number', 1);
    $response->assertJsonPath('data.scores.0.opponent.name', 'Rival FC');
    $response->assertJsonPath('data.scores.0.is_home', true);
    $response->assertJsonPath('data.scores.0.points', 9);
    $response->assertJsonPath('data.scores.0.stats', ['goals' => [1, 0]]);
    $response->assertJsonPath('data.scores.0.lineup_manager.id', $manager->id);
    $response->assertJsonPath('data.scores.0.lineup_manager.name', 'Comando Lechuga');
});

test('has a null lineup_manager when no manager fielded the player that week', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $team->id]);

    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'state' => FixtureState::Finished,
        'team_local_id' => $team->id,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $team->id,
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.scores.0.lineup_manager', null);
});

test('returns ownership activity (signing, sale, buyout) ordered by date', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);

    Activity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id,
        'occurred_at' => now()->subDay(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Shield,
        'source_season_manager_id' => $manager->id,
        'occurred_at' => now(),
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.ownership_activity');
    $response->assertJsonPath('data.ownership_activity.0.type', 'signing');
});

test('returns next_fixtures without padding', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $opponent = Team::factory()->create(['main_name' => 'Rival FC']);
    $player = Player::factory()->create(['team_id' => $team->id]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $opponent->id,
        'team_guest_id' => $team->id,
        'date' => now()->addWeek(),
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.next_fixtures');
    $response->assertJsonPath('data.next_fixtures.0.opponent.name', 'Rival FC');
    $response->assertJsonPath('data.next_fixtures.0.is_home', false);
});
