<?php

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;

test('estimates finished fixtures and marks the published ones', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    $player = Player::factory()->create(['team_id' => $home->id, 'position' => PlayerPosition::Goalkeeper]);
    $lineup = FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'fantasy_stats' => ['mins_played' => [90, 2], 'saves' => [3, 1], 'marca_points' => [-1, 3]],
    ]);

    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();

    // Portero, 90', 3 paradas, victoria, a cero: 0,25 + 1,20 + 0,60 + 0,10 + 0,25 = 2,40 → 3
    expect($lineup->fresh()->dazn_estimate)->toBe(3)
        ->and($fixture->fresh()->dazn_published)->toBeTrue();
});

test('never overwrites an existing estimate and can run twice', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    $player = Player::factory()->create(['team_id' => $home->id, 'position' => PlayerPosition::Goalkeeper]);
    $lineup = FixtureLineup::factory()->withDaznEstimate(points: 1)->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'fantasy_stats' => ['mins_played' => [90, 2], 'saves' => [3, 1], 'marca_points' => [-1, 3]],
    ]);

    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();
    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();

    expect($lineup->fresh()->dazn_estimate)->toBe(1);
});

test('skips fixtures that are not finished', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create();
    $away = Team::factory()->create();
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'state' => FixtureState::SecondHalf,
    ]);
    $player = Player::factory()->create(['team_id' => $home->id, 'position' => PlayerPosition::Goalkeeper]);
    $lineup = FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
    ]);

    $this->artisan('season:backfill-dazn-estimates')->assertSuccessful();

    expect($lineup->fresh()->dazn_estimate)->toBeNull();
});
