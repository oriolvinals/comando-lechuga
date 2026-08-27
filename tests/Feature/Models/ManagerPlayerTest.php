<?php

use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\SeasonManager;

test('belongs to a season manager and a player and stores the clause state', function (): void {
    $seasonManager = SeasonManager::factory()->create();
    $player = Player::factory()->create();
    $lockedUntil = now()->addWeek();

    $seasonManagerPlayer = ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
        'buyout_clause' => 35273936,
        'buyout_clause_locked_until' => $lockedUntil,
        'shielded' => true,
    ]);

    expect($seasonManagerPlayer->seasonManager->is($seasonManager))->toBeTrue()
        ->and($seasonManagerPlayer->player->is($player))->toBeTrue()
        ->and($seasonManagerPlayer->buyout_clause)->toBe(35273936)
        ->and($seasonManagerPlayer->buyout_clause_locked_until->toDateTimeString())->toBe($lockedUntil->toDateTimeString())
        ->and($seasonManagerPlayer->shielded)->toBeTrue()
        ->and($seasonManager->fresh())->not->toBeNull();
});
