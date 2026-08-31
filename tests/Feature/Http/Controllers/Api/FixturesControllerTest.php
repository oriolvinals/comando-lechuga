<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;

test('groups the current season fixtures by week number', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);

    $response = $this->getJson('/api/fixtures');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.week_number', 1);
    $response->assertJsonCount(2, 'data.0.fixtures');
    $response->assertJsonPath('data.1.week_number', 2);
    $response->assertJsonCount(1, 'data.1.fixtures');
});

test('orders fixtures within a week by date', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $later = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'date' => now()->addDay(),
    ]);
    $earlier = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'date' => now(),
    ]);

    $response = $this->getJson('/api/fixtures');

    $response->assertOk();
    $response->assertJsonPath('data.0.fixtures.0.id', $earlier->id);
    $response->assertJsonPath('data.0.fixtures.1.id', $later->id);
});

test('returns each fixture\'s teams, score, state and label', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $localTeam = Team::factory()->create(['main_name' => 'FC Barcelona', 'logo' => 'team/1.png']);
    $guestTeam = Team::factory()->create(['main_name' => 'Real Madrid', 'logo' => 'team/2.png']);

    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $localTeam->id,
        'team_guest_id' => $guestTeam->id,
        'local_score' => 2,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->getJson('/api/fixtures');

    $response->assertOk();
    $response->assertJsonPath('data.0.fixtures.0.id', $fixture->id);
    $response->assertJsonPath('data.0.fixtures.0.url', route('api.fixtures.show', $fixture->id));
    $response->assertJsonPath('data.0.fixtures.0.local_team.id', $localTeam->id);
    $response->assertJsonPath('data.0.fixtures.0.local_team.name', 'FC Barcelona');
    $response->assertJsonPath('data.0.fixtures.0.local_team.logo', asset('storage/team/1.png'));
    $response->assertJsonPath('data.0.fixtures.0.guest_team.name', 'Real Madrid');
    $response->assertJsonPath('data.0.fixtures.0.local_score', 2);
    $response->assertJsonPath('data.0.fixtures.0.guest_score', 1);
    $response->assertJsonPath('data.0.fixtures.0.state', 'finished');
    $response->assertJsonPath('data.0.fixtures.0.state_label', 'Finalizado');
});

test('only returns fixtures for the current season', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);

    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    Fixture::factory()->create(['season_id' => $otherSeason->id, 'week_number' => 1]);

    $response = $this->getJson('/api/fixtures');

    $response->assertOk();
    $response->assertJsonCount(1, 'data.0.fixtures');
});
