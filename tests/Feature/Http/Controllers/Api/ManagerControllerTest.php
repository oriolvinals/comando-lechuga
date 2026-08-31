<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

test('returns the manager info fields', function (): void {
    $season = Season::factory()->create();
    $manager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'name' => 'Comando Lechuga',
        'position' => 1,
        'last_position' => 2,
        'total_points' => 812,
        'value' => 123_456_789,
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $manager->id);
    $response->assertJsonPath('data.url', route('season-managers.show', $manager->id));
    $response->assertJsonPath('data.name', 'Comando Lechuga');
    $response->assertJsonPath('data.position', 1);
    $response->assertJsonPath('data.last_position', 2);
    $response->assertJsonPath('data.total_points', 812);
    $response->assertJsonPath('data.value', 123_456_789);
});

test('returns 404 for a manager that does not exist', function (): void {
    $response = $this->getJson('/api/managers/999999');

    $response->assertNotFound();
});

test('returns the current roster with the player and their team embedded', function (): void {
    $season = Season::factory()->create();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create(['main_name' => 'FC Barcelona', 'logo' => 'team/21.png']);
    $player = Player::factory()->create(['nickname' => 'Pedri', 'team_id' => $team->id, 'image' => 'players/88.png']);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'buyout_clause' => 90_000_000,
        'shielded' => true,
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.roster');
    $response->assertJsonPath('data.roster.0.player.id', $player->id);
    $response->assertJsonPath('data.roster.0.player.nickname', 'Pedri');
    $response->assertJsonPath('data.roster.0.player.team.id', $team->id);
    $response->assertJsonPath('data.roster.0.player.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.roster.0.player.team.logo', asset('storage/team/21.png'));
    $response->assertJsonPath('data.roster.0.player.image', asset('storage/players/88.png'));
    $response->assertJsonPath('data.roster.0.buyout_clause', 90_000_000);
    $response->assertJsonPath('data.roster.0.shielded', true);
});

test('returns lineup history with each player\'s points and whether their match finished', function (): void {
    $season = Season::factory()->create([
        'current_week' => 1,
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create();
    $player = Player::factory()->create(['nickname' => 'Pedri', 'team_id' => $team->id, 'image' => 'players/9.png']);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'state' => FixtureState::Finished,
        'team_local_id' => $team->id,
    ]);
    $lineup = ManagerLineup::factory()->create([
        'season_manager_id' => $manager->id,
        'week_number' => 1,
        'points' => 45,
        'tactical_formation' => [4, 4, 2],
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'position' => PlayerPosition::Midfield,
        'points' => 9,
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.lineup_history');
    $response->assertJsonPath('data.lineup_history.0.week_number', 1);
    $response->assertJsonPath('data.lineup_history.0.points', 45);
    $response->assertJsonPath('data.lineup_history.0.tactical_formation', [4, 4, 2]);
    $response->assertJsonPath('data.lineup_history.0.players.0.player.id', $player->id);
    $response->assertJsonPath('data.lineup_history.0.players.0.player.image', asset('storage/players/9.png'));
    $response->assertJsonPath('data.lineup_history.0.players.0.position', 'midfield');
    $response->assertJsonPath('data.lineup_history.0.players.0.points', 9);
    $response->assertJsonPath('data.lineup_history.0.players.0.match_finished', true);
});

test('returns the manager\'s last 10 activities as source or target, newest first', function (): void {
    $season = Season::factory()->create();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $asTarget = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'target_season_manager_id' => $manager->id,
        'occurred_at' => now(),
    ]);
    $asSource = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'occurred_at' => now()->subMinute(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'occurred_at' => now(),
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(2, 'data.recent_activity');
    $response->assertJsonPath('data.recent_activity.0.id', $asTarget->id);
    $response->assertJsonPath('data.recent_activity.1.id', $asSource->id);
});
