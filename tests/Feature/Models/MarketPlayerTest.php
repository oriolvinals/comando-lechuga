<?php

use App\Models\MarketPlayer;
use App\Models\Player;
use Carbon\CarbonImmutable;

test('belongs to a player and uses the market listing ID as its key', function () {
    $player = Player::factory()->create();
    $marketPlayer = MarketPlayer::factory()->create([
        'fantasy_id' => 14262396,
        'player_id' => $player->id,
        'expires_at' => '2026-08-22T11:28:05+02:00',
    ]);

    expect($marketPlayer->fantasy_id)->toBe(14262396)
        ->and($marketPlayer->player)->toBeInstanceOf(Player::class)
        ->and($player->marketPlayer)->toBeInstanceOf(MarketPlayer::class)
        ->and($marketPlayer->expires_at)->toEqual(CarbonImmutable::parse('2026-08-22T11:28:05+02:00'));
});
