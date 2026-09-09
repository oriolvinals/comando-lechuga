<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
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
    $response = $this->get('/equipos/999999');

    $response->assertNotFound();
});
