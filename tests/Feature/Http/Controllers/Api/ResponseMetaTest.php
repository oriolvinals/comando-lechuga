<?php

declare(strict_types=1);

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Season;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 18:30:00', 'Europe/Madrid'));
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
});

test('stamps a response with when it was generated and the timezone', function (): void {
    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('meta.generated_at', '2026-09-28T18:30:00+02:00');
    $response->assertJsonPath('meta.timezone', 'Europe/Madrid');
});

test('keeps the pagination meta of a paginated response', function (): void {
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('meta.current_page', 1);
    $response->assertJsonPath('meta.per_page', 15);
    $response->assertJsonPath('meta.generated_at', '2026-09-28T18:30:00+02:00');
});

test('stamps a 404 for an unknown id too', function (): void {
    $response = $this->getJson('/api/managers/999999');

    $response->assertNotFound();
    $response->assertJsonPath('meta.timezone', 'Europe/Madrid');
});
