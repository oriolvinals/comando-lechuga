<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;

test('returns the fixture info with teams, score and formations', function (): void {
    $season = Season::factory()->create();
    $localTeam = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $guestTeam = Team::factory()->create(['main_name' => 'Real Madrid']);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'team_local_id' => $localTeam->id,
        'team_guest_id' => $guestTeam->id,
        'local_score' => 2,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
        'local_formation' => '4-3-3',
        'guest_formation' => '4-4-2',
        'display_clock' => 'FT',
    ]);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $fixture->id);
    $response->assertJsonPath('data.url', route('fixtures.show', $fixture->id));
    $response->assertJsonPath('data.week_number', 3);
    $response->assertJsonPath('data.state', 'finished');
    $response->assertJsonPath('data.state_label', 'Finalizado');
    $response->assertJsonPath('data.display_clock', 'FT');
    $response->assertJsonPath('data.local_team.name', 'FC Barcelona');
    $response->assertJsonPath('data.guest_team.name', 'Real Madrid');
    $response->assertJsonPath('data.local_score', 2);
    $response->assertJsonPath('data.guest_score', 1);
    $response->assertJsonPath('data.local_formation', '4-3-3');
    $response->assertJsonPath('data.guest_formation', '4-4-2');
});

test('returns 404 for a fixture that does not exist', function (): void {
    $response = $this->getJson('/api/fixtures/999999');

    $response->assertNotFound();
});

test('returns the lineups with the player, points and stats', function (): void {
    $season = Season::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['nickname' => 'Pedri', 'image' => 'players/9.png']);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $fixture->team_local_id,
        'starter' => true,
        'position' => 'CentralMidfielder',
        'jersey' => '8',
        'fantasy_points' => 9,
        'fantasy_stats' => ['goals' => [1, 0]],
    ]);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.lineups');
    $response->assertJsonPath('data.lineups.0.player.id', $player->id);
    $response->assertJsonPath('data.lineups.0.player.nickname', 'Pedri');
    $response->assertJsonPath('data.lineups.0.player.image', asset('storage/players/9.png'));
    $response->assertJsonPath('data.lineups.0.starter', true);
    $response->assertJsonPath('data.lineups.0.position', 'CentralMidfielder');
    $response->assertJsonPath('data.lineups.0.jersey', '8');
    $response->assertJsonPath('data.lineups.0.points', 9);
    $response->assertJsonPath('data.lineups.0.stats', ['goals' => [1, 0]]);
});

test('falls back to worldcup26 raw stats when there is no fantasy_stats', function (): void {
    $season = Season::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(),
        'team_id' => $fixture->team_local_id,
        'fantasy_stats' => null,
        'stats' => [['name' => 'totalGoals', 'value' => 2]],
    ]);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonPath('data.lineups.0.stats.goals', [2, 0]);
});

test('returns a null player and the unresolved name for an unlinked lineup entry', function (): void {
    $season = Season::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => null,
        'unresolved_name' => 'J. Doe',
        'team_id' => $fixture->team_local_id,
    ]);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonPath('data.lineups.0.player', null);
    $response->assertJsonPath('data.lineups.0.unresolved_name', 'J. Doe');
});

test('returns the goal and card events with the scoring player', function (): void {
    $season = Season::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['nickname' => 'Lewandowski']);

    FixtureEvent::factory()->create([
        'fixture_id' => $fixture->id,
        'team_id' => $fixture->team_local_id,
        'player_id' => $player->id,
        'type' => 'goal',
        'minute' => 23,
        'is_penalty' => true,
    ]);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.events');
    $response->assertJsonPath('data.events.0.minute', 23);
    $response->assertJsonPath('data.events.0.type', 'goal');
    $response->assertJsonPath('data.events.0.is_penalty', true);
    $response->assertJsonPath('data.events.0.is_own_goal', false);
    $response->assertJsonPath('data.events.0.player.id', $player->id);
    $response->assertJsonPath('data.events.0.player.nickname', 'Lewandowski');
});

test('sums fixture_lineups stats into team_stats by team', function (): void {
    $season = Season::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(),
        'team_id' => $fixture->team_local_id,
        'stats' => [['name' => 'totalShots', 'value' => 4], ['name' => 'shotsOnTarget', 'value' => 2]],
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(),
        'team_id' => $fixture->team_guest_id,
        'stats' => [['name' => 'totalShots', 'value' => 9]],
    ]);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonPath('data.team_stats.1.stat', 'totalShots');
    $response->assertJsonPath('data.team_stats.1.label', 'Tiros totales');
    $response->assertJsonPath('data.team_stats.1.local', 4);
    $response->assertJsonPath('data.team_stats.1.guest', 9);
});
