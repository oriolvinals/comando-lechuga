<?php

use App\Console\Commands\LinkMatchDataPlayers;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureEvent;
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
    Player::factory()->create(['team_id' => $team->id, 'nickname' => 'Zzyzx', 'status' => PlayerStatus::Ok, 'position' => PlayerPosition::Midfield]);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled, 0 fixture events backfilled.')
        ->expectsOutputToContain('1 players still unresolved')
        ->assertSuccessful();
});

test('ignores coaches entirely — they never appear in a worldcup26 match roster', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    Player::factory()->create(['team_id' => $team->id, 'nickname' => 'Mourinho', 'status' => PlayerStatus::Ok, 'position' => PlayerPosition::Coach]);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled, 0 fixture events backfilled.')
        ->doesntExpectOutputToContain('unresolved')
        ->assertSuccessful();
});

test('ignores injured players entirely — they have not featured in any match yet', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Injured, 'position' => PlayerPosition::Midfield]);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled, 0 fixture events backfilled.')
        ->doesntExpectOutputToContain('unresolved')
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
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled, 0 fixture events backfilled.')
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
        ->expectsOutput('0 players linked, 0 fixture lineups backfilled, 0 fixture events backfilled.')
        ->doesntExpectOutputToContain('unresolved')
        ->assertSuccessful();

    expect($player->refresh()->match_data_id)->toBe(12345);
});

test('linkFromMap links a player found in the map and backfills its waiting fixture_lineups and fixture_events', function (): void {
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
    $event = FixtureEvent::factory()->create([
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'player_id' => null,
        'match_data_id' => 999,
        'unresolved_name' => 'Zzyzx',
    ]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, 'fantasy_id' => 42]);

    $command = new LinkMatchDataPlayers;
    $linkFromMap = new ReflectionMethod($command, 'linkFromMap');
    $result = $linkFromMap->invoke($command, collect([$player]), [42 => 999]);

    expect($result)->toBe(['linked' => 1, 'lineupsBackfilled' => 1, 'eventsBackfilled' => 1])
        ->and($player->refresh()->match_data_id)->toBe(999)
        ->and($lineup->refresh()->player_id)->toBe($player->id)
        ->and($lineup->refresh()->unresolved_name)->toBeNull()
        ->and($event->refresh()->player_id)->toBe($player->id)
        ->and($event->refresh()->unresolved_name)->toBeNull();
});
