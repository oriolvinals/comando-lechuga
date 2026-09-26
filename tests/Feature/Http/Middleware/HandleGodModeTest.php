<?php

declare(strict_types=1);

use App\Models\Player;
use App\Models\Season;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('the correct key sets the cookie and shares godMode as true', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player).'?god_mode=super-secret-key');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', true));
    $response->assertCookie('god_mode', '1');
});

test('a wrong key does nothing and leaves the cookie untouched', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player).'?god_mode=wrong-key');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
    $response->assertCookieMissing('god_mode');
});

test('the literal value 1 does nothing when the configured key is something else', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player).'?god_mode=1');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
    $response->assertCookieMissing('god_mode');
});

test('an empty configured key never enables god mode even with a value supplied', function (): void {
    config(['services.god_mode.key' => '']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player).'?god_mode=anything');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
    $response->assertCookieMissing('god_mode');
});

test('an unset configured key never enables god mode even with a value supplied', function (): void {
    config(['services.god_mode.key' => null]);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player).'?god_mode=anything');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
    $response->assertCookieMissing('god_mode');
});

test('a cookie-only request shares godMode as true', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->withCookie('god_mode', '1')->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', true));
});

test('god_mode=0 with the cookie present expires it and shares godMode as false', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->withCookie('god_mode', '1')->get(route('players.show', $player).'?god_mode=0');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
    $response->assertCookieExpired('god_mode');
});

test('a bare god_mode parameter shares godMode as false and leaves the cookie untouched', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player).'?god_mode');

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
    $response->assertCookieMissing('god_mode');
});

test('no param and no cookie shares godMode as false', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
    $response->assertCookieMissing('god_mode');
});
