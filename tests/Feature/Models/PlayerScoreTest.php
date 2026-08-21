<?php

use App\Models\Player;
use App\Models\PlayerScore;

test('belongs to a player and stores the weekly score', function (): void {
    $player = Player::factory()->create();
    $score = PlayerScore::factory()->create([
        'player_id' => $player->id,
        'points' => 14,
        'week_number' => 2,
        'stats' => ['goals' => [1, 5]],
        'ideal_formation' => true,
    ]);

    expect($score->player)->toBeInstanceOf(Player::class)
        ->and($score->points)->toBe(14)
        ->and($score->week_number)->toBe(2)
        ->and($score->stats)->toBe(['goals' => [1, 5]])
        ->and($score->ideal_formation)->toBeTrue()
        ->and($player->scores)->toHaveCount(1);
});
