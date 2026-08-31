<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

test('paginates players, 15 per page', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    Player::factory()->count(20)->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 20);
    $response->assertJsonPath('meta.per_page', 15);
    $response->assertJsonCount(15, 'data');
});

test('excludes out of league players even without a status filter', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $inLeague = Player::factory()->create(['status' => PlayerStatus::Ok]);
    Player::factory()->create(['status' => PlayerStatus::OutOfLeague]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $inLeague->id);
});

test('searches players by nickname ignoring accents', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $match = Player::factory()->create(['status' => PlayerStatus::Ok, 'nickname' => 'Óscar Valentín']);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'nickname' => 'Pedri']);

    $response = $this->getJson('/api/players?search=valentin');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $match->id);
});

test('filters players by position', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $goalkeeper = Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Goalkeeper]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Striker]);

    $response = $this->getJson('/api/players?position=goalkeeper');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $goalkeeper->id);
});

test('filters players by team', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $match = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson("/api/players?team={$team->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $match->id);
});

test('filters players by fantasy manager owner', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Comando Lechuga']);
    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $owned->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson("/api/players?season_manager={$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $owned->id);
    $response->assertJsonPath('data.0.owner_manager.id', $manager->id);
    $response->assertJsonPath('data.0.owner_manager.name', 'Comando Lechuga');
});

test('has a null owner_manager for an unowned player', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.owner_manager', null);
});

test('filters players by status', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $injured = Player::factory()->create(['status' => PlayerStatus::Injured]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson('/api/players?status=injured');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $injured->id);
});

test('sorts players by points descending by default', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $low = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 10]);
    $high = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 90]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $high->id);
    $response->assertJsonPath('data.1.id', $low->id);
});

test('sorts players by market value ascending when requested', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $cheap = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 500_000]);
    $expensive = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 5_000_000]);

    $response = $this->getJson('/api/players?sort=value&direction=asc');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $cheap->id);
    $response->assertJsonPath('data.1.id', $expensive->id);
});

test('sorts players by market value difference when requested', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $riser = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value_difference' => 50_000]);
    $faller = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value_difference' => -20_000]);

    $response = $this->getJson('/api/players?sort=difference');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $riser->id);
    $response->assertJsonPath('data.1.id', $faller->id);
});

test('returns player fields including team and market data', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create(['main_name' => 'FC Barcelona', 'logo' => 'team/4.png']);
    $player = Player::factory()->create([
        'status' => PlayerStatus::Ok,
        'nickname' => 'Pedri',
        'image' => 'players/9.png',
        'team_id' => $team->id,
        'position' => PlayerPosition::Midfield,
        'market_value' => 45_000_000,
        'market_value_difference' => -100_000,
        'points' => 120,
        'average_points' => 6.5,
    ]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.url', route('api.players.show', $player->id));
    $response->assertJsonPath('data.0.nickname', 'Pedri');
    $response->assertJsonPath('data.0.image', asset('storage/players/9.png'));
    $response->assertJsonPath('data.0.status', 'ok');
    $response->assertJsonPath('data.0.position', 'midfield');
    $response->assertJsonPath('data.0.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.0.team.logo', asset('storage/team/4.png'));
    $response->assertJsonPath('data.0.market_value', 45_000_000);
    $response->assertJsonPath('data.0.market_value_difference', -100_000);
    $response->assertJsonPath('data.0.points', 120);
});

test('returns recent_scores only for the team\'s finished fixtures, without padding', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $opponent = Team::factory()->create(['main_name' => 'Rival FC']);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);

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
        'fantasy_points' => 9,
    ]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.recent_scores', [
        ['week_number' => 1, 'opponent' => ['id' => $opponent->id, 'name' => 'Rival FC', 'logo' => ''], 'points' => 9],
    ]);
});

test('returns next_fixtures only for the team\'s scheduled fixtures, without padding', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $opponent = Team::factory()->create(['main_name' => 'Rival FC']);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $opponent->id,
        'team_guest_id' => $team->id,
        'date' => now()->addWeek(),
    ]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonCount(1, 'data.0.next_fixtures');
    $response->assertJsonPath('data.0.next_fixtures.0.week_number', 5);
    $response->assertJsonPath('data.0.next_fixtures.0.opponent.name', 'Rival FC');
    $response->assertJsonPath('data.0.next_fixtures.0.is_home', false);
    expect($player)->not->toBeNull();
});
