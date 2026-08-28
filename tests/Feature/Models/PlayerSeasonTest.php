<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;

test('casts its position enum and belongs to a player and a season', function (): void {
    $player = Player::factory()->create();
    $season = Season::factory()->create();

    $playerSeason = PlayerSeason::factory()->create([
        'player_id' => $player->id,
        'season_id' => $season->id,
        'position' => PlayerPosition::Striker,
    ]);

    expect($playerSeason->position)->toBe(PlayerPosition::Striker)
        ->and($playerSeason->player->id)->toBe($player->id)
        ->and($playerSeason->season->id)->toBe($season->id);
});
