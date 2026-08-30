<?php

use App\Console\Commands\ListUnlinkedMatchDataPlayers;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;

test('lists unresolved fixture_lineups rows', function (): void {
    $team = Team::factory()->create();
    $fixture = Fixture::factory()->create(['team_local_id' => $team->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'player_id' => null,
        'unresolved_name' => 'Zzyzx',
        'match_data_id' => 999,
        'jersey' => '7',
    ]);

    expect(Artisan::call(ListUnlinkedMatchDataPlayers::class))->toBe(0);

    $output = Artisan::output();
    expect($output)->toContain('Zzyzx')->toContain('999');
});

test('excludes rows already linked to a player', function (): void {
    $team = Team::factory()->create();
    $fixture = Fixture::factory()->create(['team_local_id' => $team->id]);
    $player = Player::factory()->create(['team_id' => $team->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'player_id' => $player->id,
        'unresolved_name' => null,
    ]);

    expect(Artisan::call(ListUnlinkedMatchDataPlayers::class))->toBe(0);
    expect(Artisan::output())->toContain('No unlinked match-data players.');
});

test('dedupes the same unresolved athlete seen across multiple fixtures into a single row', function (): void {
    $team = Team::factory()->create();
    $firstFixture = Fixture::factory()->create(['team_local_id' => $team->id, 'date' => now()->subDays(2)]);
    $secondFixture = Fixture::factory()->create(['team_local_id' => $team->id, 'date' => now()->subDay()]);
    FixtureLineup::factory()->create([
        'fixture_id' => $firstFixture->id,
        'team_id' => $team->id,
        'player_id' => null,
        'unresolved_name' => 'Zzyzx',
        'match_data_id' => 999,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $secondFixture->id,
        'team_id' => $team->id,
        'player_id' => null,
        'unresolved_name' => 'Zzyzx',
        'match_data_id' => 999,
    ]);

    Artisan::call(ListUnlinkedMatchDataPlayers::class);

    expect(substr_count(Artisan::output(), 'Zzyzx'))->toBe(1);
});
