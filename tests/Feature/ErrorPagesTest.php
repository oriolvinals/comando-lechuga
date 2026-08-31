<?php

declare(strict_types=1);

use App\Models\Player;
use App\Models\Season;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the error page for a 404 raised inside a matched route', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create(['fantasy_id' => null]);

    $response = $this->get(route('players.show', $player));

    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('errors/error')
        ->where('status', 404)
    );
});

test('renders the error page with season data for a route that matches nothing', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 9]);

    $response = $this->get('/this-route-does-not-exist');

    $response->assertNotFound();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('errors/error')
        ->where('status', 404)
        ->where('season.current_week', 9)
    );
});
