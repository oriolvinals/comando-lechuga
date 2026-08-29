<?php

use App\Enums\PlayerPosition;
use App\Models\FixtureLineup;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;

test('belongs to a lineup and player, and has no points/stats columns of its own', function (): void {
    $lineup = ManagerLineup::factory()->create();
    $player = Player::factory()->create();
    $lineupPlayer = ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'position' => PlayerPosition::Goalkeeper,
    ]);

    expect($lineupPlayer->lineup)->toBeInstanceOf(ManagerLineup::class)
        ->and($lineupPlayer->player)->toBeInstanceOf(Player::class)
        ->and($lineupPlayer->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($player->lineupPlayers)->toHaveCount(1);
});

test('fixtureLineup resolves the matching FixtureLineup row by fixture_id and player_id', function (): void {
    $fixture = Fixture::factory()->create();
    $player = Player::factory()->create();
    $matchingLineup = FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'fantasy_points' => 8,
    ]);
    // A different player's row on the same fixture must not match.
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id]);

    $lineupPlayer = ManagerLineupPlayer::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
    ]);

    expect($lineupPlayer->fixtureLineup)->not->toBeNull()
        ->and($lineupPlayer->fixtureLineup->id)->toBe($matchingLineup->id)
        ->and($lineupPlayer->fixtureLineup->fantasy_points)->toBe(8);
});

test('fixtureLineup is null when fixture_id is not yet set (lineup set before the match is synced)', function (): void {
    $lineupPlayer = ManagerLineupPlayer::factory()->create(['fixture_id' => null]);

    expect($lineupPlayer->fixtureLineup)->toBeNull();
});
