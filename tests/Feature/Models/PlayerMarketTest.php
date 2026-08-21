<?php

use App\Models\Player;
use App\Models\PlayerMarket;
use Carbon\CarbonImmutable;

test('belongs to a player and stores a daily value', function (): void {
    $player = Player::factory()->create(['market_value_difference' => -500000]);
    $market = PlayerMarket::factory()->create([
        'fantasy_id' => 68,
        'player_id' => $player->id,
        'date' => '2026-08-21',
        'value' => 15000000,
    ]);

    expect($market->player)->toBeInstanceOf(Player::class)
        ->and($market->fantasy_id)->toBe(68)
        ->and($market->date)->toEqual(CarbonImmutable::parse('2026-08-21'))
        ->and($market->value)->toBe(15000000)
        ->and($player->market_value_difference)->toBe(-500000)
        ->and($player->markets)->toHaveCount(1);
});
