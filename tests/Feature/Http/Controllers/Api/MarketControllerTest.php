<?php

declare(strict_types=1);

use App\Enums\PlayerPosition;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;

test('returns the current market listings ordered by soonest to expire', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $player = Player::factory()->create([
        'nickname' => 'Pedri',
        'team_id' => $team->id,
        'position' => PlayerPosition::Midfield,
    ]);

    $soonest = MarketPlayer::factory()->create([
        'player_id' => $player->id,
        'expires_at' => now()->addHours(2),
        'sale_price' => 5_000_000,
        'value' => 4_800_000,
        'bids' => 3,
    ]);
    $later = MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create(['position' => PlayerPosition::Striker])->id,
        'expires_at' => now()->addHours(20),
    ]);

    $response = $this->getJson('/api/market');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.player.id', $player->id);
    $response->assertJsonPath('data.0.player.nickname', 'Pedri');
    $response->assertJsonPath('data.0.player.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.0.sale_price', 5_000_000);
    $response->assertJsonPath('data.0.value', 4_800_000);
    $response->assertJsonPath('data.0.bids', 3);
    $response->assertJsonPath('data.0.expires_at', $soonest->expires_at->toIso8601String());
    $response->assertJsonPath('data.1.expires_at', $later->expires_at->toIso8601String());
});

test('excludes listings that already expired', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create()->id,
        'expires_at' => now()->subHour(),
    ]);

    $response = $this->getJson('/api/market');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});

test('excludes coaches from the market listing', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $coach = Player::factory()->create(['position' => PlayerPosition::Coach]);
    MarketPlayer::factory()->create([
        'player_id' => $coach->id,
        'expires_at' => now()->addHours(2),
    ]);

    $response = $this->getJson('/api/market');

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});
