<?php

use App\Enums\PlayerPosition;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;

test('belongs to a lineup and player', function (): void {
    $lineup = ManagerLineup::factory()->create();
    $player = Player::factory()->create();
    $lineupPlayer = ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'points' => 6,
        'position' => PlayerPosition::Goalkeeper,
    ]);

    expect($lineupPlayer->lineup)->toBeInstanceOf(ManagerLineup::class)
        ->and($lineupPlayer->player)->toBeInstanceOf(Player::class)
        ->and($lineupPlayer->points)->toBe(6)
        ->and($lineupPlayer->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($player->lineupPlayers)->toHaveCount(1);
});
