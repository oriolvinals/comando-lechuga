<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\SeasonManagerLineup;
use App\Models\SeasonManagerLineupPlayer;

test('belongs to a lineup and player', function (): void {
    $lineup = SeasonManagerLineup::factory()->create();
    $player = Player::factory()->create();
    $lineupPlayer = SeasonManagerLineupPlayer::factory()->create([
        'season_manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'points' => 6,
        'position' => PlayerPosition::Goalkeeper,
    ]);

    expect($lineupPlayer->lineup)->toBeInstanceOf(SeasonManagerLineup::class)
        ->and($lineupPlayer->player)->toBeInstanceOf(Player::class)
        ->and($lineupPlayer->points)->toBe(6)
        ->and($lineupPlayer->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($player->lineupPlayers)->toHaveCount(1);
});
