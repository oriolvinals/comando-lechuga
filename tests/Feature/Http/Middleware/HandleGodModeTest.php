<?php

declare(strict_types=1);

use App\Models\Player;
use App\Models\Season;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('the correct key redirects to the same url without the param and sets the cookie', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->get($target.'?god_mode=super-secret-key');

    $response->assertRedirect($target);
    $response->assertCookie('god_mode', '1');

    $followUp = $this->withCookie('god_mode', '1')->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', true));
});

test('the redirect keeps every other query param, only stripping god_mode', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->get($target.'?god_mode=super-secret-key&confianza=80');

    $response->assertRedirect($target.'?confianza=80');
    $response->assertCookie('god_mode', '1');
});

test('a wrong key redirects without enabling god mode and without touching the cookie', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->get($target.'?god_mode=wrong-key');

    $response->assertRedirect($target);
    $response->assertCookieMissing('god_mode');

    $followUp = $this->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('a wrong key never revokes an already-remembered valid session', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->withCookie('god_mode', '1')->get($target.'?god_mode=wrong-key');

    $response->assertRedirect($target);
    // Neither a fresh "1" nor an expiry is queued: the pre-existing cookie is
    // left exactly as the browser already had it.
    $response->assertCookieMissing('god_mode');

    $followUp = $this->withCookie('god_mode', '1')->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', true));
});

test('the literal value 1 does nothing when the configured key is something else', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->get($target.'?god_mode=1');

    $response->assertRedirect($target);
    $response->assertCookieMissing('god_mode');

    $followUp = $this->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('an empty configured key never enables god mode even with a value supplied', function (): void {
    config(['services.god_mode.key' => '']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->get($target.'?god_mode=anything');

    $response->assertRedirect($target);
    $response->assertCookieMissing('god_mode');

    $followUp = $this->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('an unset configured key never enables god mode even with a value supplied', function (): void {
    config(['services.god_mode.key' => null]);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->get($target.'?god_mode=anything');

    $response->assertRedirect($target);
    $response->assertCookieMissing('god_mode');

    $followUp = $this->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('a cookie-only request shares godMode as true with no redirect', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->withCookie('god_mode', '1')->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', true));
});

test('a cookie value other than 1 shares godMode as false', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->withCookie('god_mode', '0')->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('a forged, unencrypted god_mode cookie is rejected and shares godMode as false', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    // Laravel's EncryptCookies middleware fails to decrypt a plain-text
    // cookie value and drops it before this middleware ever sees it — proving
    // the cookie can't be forged without the app's encryption key.
    $response = $this->withUnencryptedCookie('god_mode', '1')->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('god_mode=0 with the cookie present redirects, expires the cookie on the redirect, and disables god mode', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->withCookie('god_mode', '1')->get($target.'?god_mode=0');

    $response->assertRedirect($target);
    $response->assertCookieExpired('god_mode');

    // The test client doesn't drop a cookie set earlier via withCookie() on
    // its own — simulate the browser actually honoring the expiry above.
    $this->defaultCookies = [];

    $followUp = $this->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('a bare god_mode parameter redirects without enabling god mode and without touching the cookie', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->get($target.'?god_mode');

    $response->assertRedirect($target);
    $response->assertCookieMissing('god_mode');

    $followUp = $this->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
});

test('a bare god_mode parameter never revokes an already-remembered valid session', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $target = route('players.show', $player);

    $response = $this->withCookie('god_mode', '1')->get($target.'?god_mode');

    $response->assertRedirect($target);
    $response->assertCookieMissing('god_mode');

    $followUp = $this->withCookie('god_mode', '1')->get($target);
    $followUp->assertOk();
    $followUp->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', true));
});

test('an array-valued god_mode parameter is ignored, with no redirect', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player).'?god_mode[]=x');

    $response->assertOk();
    $response->assertCookieMissing('god_mode');
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('godMode', false));
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
