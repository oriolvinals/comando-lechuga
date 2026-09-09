<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

test('orders standings by points then goal difference', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $alpha = Team::factory()->create(['main_name' => 'Alpha FC']);
    $beta = Team::factory()->create(['main_name' => 'Beta FC']);
    $gamma = Team::factory()->create(['main_name' => 'Gamma FC']);
    $season->teams()->attach([$alpha->id, $beta->id, $gamma->id]);

    // Alpha beats Gamma 3-0: Alpha 3pts/+3GD, Gamma 0pts/-3GD.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $alpha->id,
        'team_guest_id' => $gamma->id,
        'local_score' => 3,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    // Beta beats Gamma 1-0: Beta 3pts/+1GD, Gamma another 0pts/-1GD (cumulative -4GD).
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $beta->id,
        'team_guest_id' => $gamma->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    // A scheduled (not finished) fixture must not affect the table at all.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'team_local_id' => $gamma->id,
        'team_guest_id' => $alpha->id,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $alpha->id) // 3pts, +3 GD
        ->where('standings.0.position', 1)
        ->where('standings.1.team.id', $beta->id) // 3pts, +1 GD — same points, worse GD
        ->where('standings.1.position', 2)
        ->where('standings.2.team.id', $gamma->id) // 0pts
        ->where('standings.2.position', 3)
        ->where('standings.2.played', 2)
        ->where('standings.2.goal_difference', -4)
    );
});

test('a team with no finished fixtures appears with every stat zeroed', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->where('standings.0.played', 0)
        ->where('standings.0.won', 0)
        ->where('standings.0.drawn', 0)
        ->where('standings.0.lost', 0)
        ->where('standings.0.goals_for', 0)
        ->where('standings.0.goals_against', 0)
        ->where('standings.0.goal_difference', 0)
        ->where('standings.0.points', 0)
    );
});

test('shows a team by id', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['main_name' => 'Rayo Vallecano']);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('team.id', $team->id)
        ->where('team.main_name', 'Rayo Vallecano')
    );
});

test('returns 404 for an unknown team', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $response = $this->get('/equipos/999999');

    $response->assertNotFound();
});

test('a live match counts toward the table but not toward recent form', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::FirstHalf,
    ]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->where('standings.0.played', 1)
        ->where('standings.0.points', 3)
        ->where('standings.0.is_live', true)
        ->where('standings.0.recent_form', [])
        ->where('standings.1.is_live', true)
    );
});

test('the ficha squad includes the club players and excludes out-of-league ones', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    $keeper = Player::factory()->create([
        'team_id' => $team->id,
        'status' => PlayerStatus::Ok,
        'position' => PlayerPosition::Goalkeeper,
    ]);
    $outOfLeague = Player::factory()->create([
        'team_id' => $team->id,
        'status' => PlayerStatus::OutOfLeague,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('squad', 1)
        ->where('squad.0.id', $keeper->id)
    );
    expect($outOfLeague)->not->toBeNull(); // keeps the variable "used" for readability of intent
});

test('the ficha exposes this team\'s own position in the real standings', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 2,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standing.position', 1)
        ->where('standing.points', 3)
        ->where('standing.played', 1)
    );
});

test('recent form holds only the last 5 finished results, oldest first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);

    // 6 finished matches across weeks 1-6: week1 win, week2 loss, week3 draw, week4 win, week5 win, week6 loss.
    // Only the last 5 (weeks 2-6) should appear, oldest first: loss, draw, win, win, loss.
    $results = [1 => [2, 0], 2 => [0, 1], 3 => [1, 1], 4 => [3, 0], 5 => [2, 1], 6 => [0, 2]];

    foreach ($results as $week => [$local, $guest]) {
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => $week,
            'team_local_id' => $team->id,
            'team_guest_id' => $rival->id,
            'local_score' => $local,
            'guest_score' => $guest,
            'state' => FixtureState::Finished,
        ]);
    }

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->where('standings.0.recent_form', ['loss', 'draw', 'win', 'win', 'loss'])
    );
});

test('the ficha calendar includes both played and upcoming fixtures, ordered by week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $future = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Scheduled,
    ]);
    $past = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $rival->id,
        'team_guest_id' => $team->id,
        'local_score' => 1,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('fixtures', 2)
        ->where('fixtures.0.id', $past->id)
        ->where('fixtures.1.id', $future->id)
    );
});

test('the pitch defaults to the latest jornada with a synced lineup, even ahead of season.current_week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 2,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);
    $starter = Player::factory()->create([
        'team_id' => $team->id,
        'position' => PlayerPosition::Striker,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $starter->id,
        'team_id' => $team->id,
        'starter' => true,
        'fantasy_points' => 9,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('currentWeek', 3)
        ->has('weeklyLineups', 1)
        ->where('weeklyLineups.0.week_number', 3)
        ->has('weeklyLineups.0.players', 1)
        ->where('weeklyLineups.0.players.0.player.id', $starter->id)
        ->where('weeklyLineups.0.players.0.points', 9)
        ->where('weeklyLineups.0.players.0.position', 'striker')
    );
});

test('a jornada with no synced starting XI is omitted from weeklyLineups', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('weeklyLineups', 0)
        ->where('currentWeek', 1)
    );
});

test('a bench player (starter=false) does not appear on the pitch', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Finished,
        'local_score' => 1,
        'guest_score' => 0,
    ]);
    $bench = Player::factory()->create(['team_id' => $team->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $bench->id,
        'team_id' => $team->id,
        'starter' => false,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page->has('weeklyLineups', 0));
});

test('next fixtures are padded to 3, only scheduled, ordered by date, and resolve opponent/is_home correctly', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rivalA = Team::factory()->create();
    $rivalB = Team::factory()->create();
    $season->teams()->attach([$team->id, $rivalA->id, $rivalB->id]);

    // Already finished — must NOT appear among next fixtures.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rivalA->id,
        'state' => FixtureState::Finished,
        'local_score' => 1,
        'guest_score' => 0,
        'date' => now()->subDays(3),
    ]);
    // Scheduled, team away — soonest.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'team_local_id' => $rivalA->id,
        'team_guest_id' => $team->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addDays(3),
    ]);
    // Scheduled, team home — later.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'team_local_id' => $team->id,
        'team_guest_id' => $rivalB->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addDays(10),
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('nextFixtures.0.week_number', 2)
        ->where('nextFixtures.0.opponent.id', $rivalA->id)
        ->where('nextFixtures.0.is_home', false)
        ->where('nextFixtures.1.week_number', 3)
        ->where('nextFixtures.1.opponent.id', $rivalB->id)
        ->where('nextFixtures.1.is_home', true)
        ->where('nextFixtures.2', null)
    );
});

test('a starter with no resolved player is dropped from the pitch instead of crashing', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Finished,
        'local_score' => 1,
        'guest_score' => 0,
    ]);
    $resolved = Player::factory()->create([
        'team_id' => $team->id,
        'position' => PlayerPosition::Striker,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $resolved->id,
        'team_id' => $team->id,
        'starter' => true,
        'fantasy_points' => 7,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => null,
        'unresolved_name' => 'Jugador sin vincular',
        'team_id' => $team->id,
        'starter' => true,
        'fantasy_points' => null,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('weeklyLineups.0.players', 1)
        ->where('weeklyLineups.0.players.0.player.id', $resolved->id)
    );
});
