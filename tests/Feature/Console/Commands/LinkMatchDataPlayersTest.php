<?php

use App\Console\Commands\LinkMatchDataPlayers;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;

test('links no one and reports every eligible player as unresolved when none are in PLAYER_MAP', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    Player::factory()->create(['team_id' => $team->id, 'nickname' => 'Zzyzx', 'status' => PlayerStatus::Ok]);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled.')
        ->expectsOutputToContain('1 players still unresolved')
        ->assertSuccessful();
});

test('ignores out_of_league players entirely', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::OutOfLeague]);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled.')
        ->doesntExpectOutputToContain('unresolved')
        ->assertSuccessful();
});

test('leaves an already-linked player untouched and out of the unresolved count', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, 'match_data_id' => 12345]);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled.')
        ->doesntExpectOutputToContain('unresolved')
        ->assertSuccessful();

    expect($player->refresh()->match_data_id)->toBe(12345);
});

test('linkFromMap links a player found in the map and backfills its waiting fixture_lineups', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    $fixture = Fixture::factory()->create(['team_local_id' => $team->id]);
    $lineup = FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'player_id' => null,
        'unresolved_name' => 'Zzyzx',
        'match_data_id' => 999,
    ]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, 'fantasy_id' => 42]);

    $command = new LinkMatchDataPlayers;
    $linkFromMap = new ReflectionMethod($command, 'linkFromMap');
    $result = $linkFromMap->invoke($command, collect([$player]), [42 => 999]);

    expect($result)->toBe(['linked' => 1, 'lineupsBackfilled' => 1])
        ->and($player->refresh()->match_data_id)->toBe(999)
        ->and($lineup->refresh()->player_id)->toBe($player->id)
        ->and($lineup->refresh()->unresolved_name)->toBeNull();
});
