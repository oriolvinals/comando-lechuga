<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;

/**
 * @return array{0: Season, 1: Team, 2: Team}
 */
function teamsApiLeague(): array
{
    $season = Season::factory()->create([
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(200),
        'current_week' => 2,
    ]);
    $barcelona = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $madrid = Team::factory()->create(['main_name' => 'Real Madrid']);
    $season->teams()->attach([$barcelona->id, $madrid->id]);

    return [$season, $barcelona, $madrid];
}

test('returns the real LaLiga table with each team\'s record and recent form', function (): void {
    [$season, $barcelona, $madrid] = teamsApiLeague();
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'state' => FixtureState::Finished,
        'team_local_id' => $barcelona->id,
        'team_guest_id' => $madrid->id,
        'local_score' => 2,
        'guest_score' => 1,
        'date' => now()->subDays(7),
    ]);

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.rank', 1);
    $response->assertJsonPath('data.0.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.0.played', 1);
    $response->assertJsonPath('data.0.won', 1);
    $response->assertJsonPath('data.0.points', 3);
    $response->assertJsonPath('data.0.goals_for', 2);
    $response->assertJsonPath('data.0.goals_against', 1);
    $response->assertJsonPath('data.0.goal_difference', 1);
    $response->assertJsonPath('data.0.recent_form.0.fixture_id', $fixture->id);
    $response->assertJsonPath('data.0.recent_form.0.opponent.name', 'Real Madrid');
    $response->assertJsonPath('data.0.recent_form.0.score', '2-1');
    $response->assertJsonPath('data.0.recent_form.0.result', 'win');
    $response->assertJsonPath('data.0.live', null);
    $response->assertJsonPath('data.1.rank', 2);
    $response->assertJsonPath('data.1.recent_form.0.result', 'loss');
});

test('adds each team\'s next match with its probable lineup from FútbolFantasy', function (): void {
    [$season, $barcelona, $madrid] = teamsApiLeague();
    $next = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $barcelona->id,
        'team_guest_id' => $madrid->id,
        'date' => now()->addDays(2),
    ]);
    $pedri = Player::factory()->create([
        'nickname' => 'Pedri',
        'status' => PlayerStatus::Ok,
        'team_id' => $barcelona->id,
        'position' => PlayerPosition::Midfield,
    ]);
    FixtureLineupProbability::factory()->onPitch(50, 40)->create([
        'fixture_id' => $next->id,
        'player_id' => $pedri->id,
        'probability' => 90,
    ]);

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonPath('data.0.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.0.next_fixture.fixture_id', $next->id);
    $response->assertJsonPath('data.0.next_fixture.week_number', 2);
    $response->assertJsonPath('data.0.next_fixture.is_home', true);
    $response->assertJsonPath('data.0.next_fixture.opponent.name', 'Real Madrid');
    $response->assertJsonPath('data.0.next_fixture.lineup.source', 'futbolfantasy');
    $response->assertJsonPath('data.0.next_fixture.lineup.confirmed', false);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.player.nickname', 'Pedri');
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.player.position', 'midfield');
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.probability', 90);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.predicted_starter', true);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.confirmed_starter', null);
    $response->assertJsonPath('data.1.next_fixture.is_home', false);
    $response->assertJsonPath('data.1.next_fixture.lineup', null);
});

test('uses the confirmed worldcup26 lineup once there is one', function (): void {
    [$season, $barcelona, $madrid] = teamsApiLeague();
    $next = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $barcelona->id,
        'team_guest_id' => $madrid->id,
        'date' => now()->addHours(1),
    ]);
    $pedri = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $barcelona->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $next->id,
        'player_id' => $pedri->id,
        'team_id' => $barcelona->id,
        'starter' => true,
        'position' => 'Center Midfielder',
    ]);

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_fixture.lineup.source', 'worldcup26');
    $response->assertJsonPath('data.0.next_fixture.lineup.confirmed', true);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.confirmed_starter', true);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.probability', null);
});

test('has a null next fixture when the team has no match left', function (): void {
    teamsApiLeague();

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_fixture', null);
});
