<?php

use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Team;

test('casts its stats to an array and its booleans', function (): void {
    $lineup = FixtureLineup::factory()->create([
        'fixture_id' => Fixture::factory(),
        'player_id' => Player::factory(),
        'team_id' => Team::factory(),
        'starter' => false,
        'subbed_in' => true,
        'stats' => [['name' => 'saves', 'value' => 2]],
    ]);

    expect($lineup->starter)->toBeFalse()
        ->and($lineup->subbed_in)->toBeTrue()
        ->and($lineup->stats)->toBe([['name' => 'saves', 'value' => 2]]);
});

test('belongs to a fixture, a player, a team, and optionally a counterpart player', function (): void {
    $counterpart = Player::factory()->create();
    $lineup = FixtureLineup::factory()->create([
        'fixture_id' => Fixture::factory(),
        'player_id' => Player::factory(),
        'team_id' => Team::factory(),
        'counterpart_player_id' => $counterpart->id,
    ]);

    expect($lineup->fixture)->toBeInstanceOf(Fixture::class)
        ->and($lineup->player)->toBeInstanceOf(Player::class)
        ->and($lineup->team)->toBeInstanceOf(Team::class)
        ->and($lineup->counterpartPlayer->id)->toBe($counterpart->id);
});

test('stores fantasy points and stats alongside the worldcup26 raw stats', function (): void {
    $lineup = FixtureLineup::factory()->create([
        'stats' => [['name' => 'totalGoals', 'value' => 1]],
        'fantasy_points' => 8,
        'fantasy_stats' => ['marca_points' => [3, 1]],
    ]);

    expect($lineup->stats)->toBe([['name' => 'totalGoals', 'value' => 1]])
        ->and($lineup->fantasy_points)->toBe(8)
        ->and($lineup->fantasy_stats)->toBe(['marca_points' => [3, 1]]);
});

test('fantasy_points and fantasy_stats default to null', function (): void {
    $lineup = FixtureLineup::factory()->create();

    expect($lineup->fantasy_points)->toBeNull()
        ->and($lineup->fantasy_stats)->toBeNull();
});

test('stores the worldcup26 display name for an unresolved lineup entry', function (): void {
    $lineup = FixtureLineup::factory()->create([
        'player_id' => null,
        'unresolved_name' => 'Unknown Player',
    ]);

    expect($lineup->unresolved_name)->toBe('Unknown Player');
});

test('unresolved_name defaults to null', function (): void {
    $lineup = FixtureLineup::factory()->create();

    expect($lineup->unresolved_name)->toBeNull();
});

test('casts the DAZN estimate columns', function (): void {
    $lineup = FixtureLineup::factory()->withDaznEstimate(points: 3, minutes: 67, source: 'worldcup26', reasons: ['67 minutos jugados', '1 gol'])->create()->fresh();

    expect($lineup->dazn_estimate)->toBe(3)
        ->and($lineup->dazn_estimate_version)->toBe('v1')
        ->and($lineup->dazn_estimate_meta)->toBe(['source' => 'worldcup26', 'minutes' => 67, 'reasons' => ['67 minutos jugados', '1 gol']]);
});

test('defaults to no DAZN estimate', function (): void {
    $lineup = FixtureLineup::factory()->create()->fresh();

    expect($lineup->dazn_estimate)->toBeNull()
        ->and($lineup->dazn_estimate_version)->toBe('')
        ->and($lineup->dazn_estimate_meta)->toBeNull();
});
