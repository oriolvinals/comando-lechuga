<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;

test('returns the standings ordered by position', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $third = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 3]);
    $first = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);
    $second = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 2]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $first->id);
    $response->assertJsonPath('data.1.id', $second->id);
    $response->assertJsonPath('data.2.id', $third->id);
});

test('includes the full manager page url', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.url', route('api.managers.show', $manager->id));
});

test('never exposes a live_points field', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::FirstHalf,
    ]);
    SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 17]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    expect($response->json('data.0'))->not->toHaveKey('live_points');
});

test('recent_form has no live entry when the current week has not started', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Scheduled,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 4,
        'state' => FixtureState::Finished,
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 17]);
    ManagerLineup::factory()->create([
        'season_manager_id' => $manager->id,
        'week_number' => 4,
        'points' => 30,
    ]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.recent_form', [
        ['week_number' => 4, 'points' => 30, 'live' => false],
    ]);
});

test('recent_form appends the live current week and keeps only the 2 latest finished jornadas', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::FirstHalf,
    ]);
    foreach ([2, 3, 4] as $week) {
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => $week,
            'state' => FixtureState::Finished,
        ]);
    }
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 17]);
    foreach ([2 => 10, 3 => 20, 4 => 30] as $week => $points) {
        ManagerLineup::factory()->create([
            'season_manager_id' => $manager->id,
            'week_number' => $week,
            'points' => $points,
        ]);
    }

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.recent_form', [
        ['week_number' => 3, 'points' => 20, 'live' => false],
        ['week_number' => 4, 'points' => 30, 'live' => false],
        ['week_number' => 5, 'points' => 17, 'live' => true],
    ]);
});

test('recent_form has no duplicate live entry once the current week has finished but the season has not advanced yet', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Finished,
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 17]);
    ManagerLineup::factory()->create([
        'season_manager_id' => $manager->id,
        'week_number' => 5,
        'points' => 17,
    ]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.recent_form', [
        ['week_number' => 5, 'points' => 17, 'live' => false],
    ]);
});

test('recent_form has fewer than 3 entries when fewer jornadas have finished', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'state' => FixtureState::Finished,
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    ManagerLineup::factory()->create([
        'season_manager_id' => $manager->id,
        'week_number' => 1,
        'points' => 42,
    ]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.recent_form', [
        ['week_number' => 1, 'points' => 42, 'live' => false],
    ]);
});
