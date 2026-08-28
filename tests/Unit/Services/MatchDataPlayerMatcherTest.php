<?php

use App\Models\Player;
use App\Services\MatchDataPlayerMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

// tests/Pest.php only binds Tests\TestCase (and boots the Laravel app) for the
// 'Feature' suite, not 'Unit' — this is the first Unit test to touch an Eloquent
// factory. Player::factory()->make() looks DB-free at a glance, but PlayerFactory's
// definition() defaults 'team_id' to Team::factory() (a nested factory, not a plain
// value), and Laravel always resolves a BelongsTo-shaped nested factory by calling
// ->create() on it to get a real foreign key — even when the outer factory call is
// make(), not create(). So this test genuinely needs a working DB connection (to
// persist the Team row) and a booted app (to resolve Faker\Generator via
// DatabaseServiceProvider), despite the "no DB needed" framing in the task brief.
// Opting into TestCase + RefreshDatabase here, scoped to just this file via Pest's
// uses(), gets both without touching tests/Pest.php or any other test's setup.
uses(TestCase::class, RefreshDatabase::class);

test('matches when the nickname equals the full name exactly', function (): void {
    $player = Player::factory()->make(['id' => 1, 'nickname' => 'Saba Sazonov']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$player]),
        [['id' => 100, 'displayName' => 'Saba Sazonov']],
    );

    expect($result)->toBe([1 => 100]);
});

test('matches by surname as a whole word in the full name', function (): void {
    $player = Player::factory()->make(['id' => 1, 'nickname' => 'Sivera']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$player]),
        [['id' => 100, 'displayName' => 'Antonio Sivera']],
    );

    expect($result)->toBe([1 => 100]);
});

test('matches surname after folding accents', function (): void {
    $player = Player::factory()->make(['id' => 1, 'nickname' => 'Kounde']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$player]),
        [['id' => 100, 'displayName' => 'Jules Koundé']],
    );

    expect($result)->toBe([1 => 100]);
});

test('matches a first-name-only nickname as a prefix of the full name (diminutive)', function (): void {
    $player = Player::factory()->make(['id' => 1, 'nickname' => 'Vini Jr.']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$player]),
        [['id' => 100, 'displayName' => 'Vinícius Júnior']],
    );

    expect($result)->toBe([1 => 100]);
});

test('matches an initial-plus-surname nickname', function (): void {
    $player = Player::factory()->make(['id' => 1, 'nickname' => 'T. Martínez']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$player]),
        [['id' => 100, 'displayName' => 'Toni Martínez']],
    );

    expect($result)->toBe([1 => 100]);
});

test('leaves both unresolved when two roster entries share the same surname', function (): void {
    $player = Player::factory()->make(['id' => 1, 'nickname' => 'García']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$player]),
        [
            ['id' => 100, 'displayName' => 'Andrés García'],
            ['id' => 101, 'displayName' => 'Kike García'],
        ],
    );

    expect($result)->toBe([]);
});

test('a tighter rule resolving one player frees up a looser match for another', function (): void {
    // Both nicknames could plausibly match "García" by surname alone, but
    // "A. García" only has one candidate under the initial+surname rule,
    // and once it's resolved and removed, "García" alone becomes unambiguous.
    $exact = Player::factory()->make(['id' => 1, 'nickname' => 'A. García']);
    $surnameOnly = Player::factory()->make(['id' => 2, 'nickname' => 'García']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$exact, $surnameOnly]),
        [
            ['id' => 100, 'displayName' => 'Andrés García'],
            ['id' => 101, 'displayName' => 'Kike García'],
        ],
    );

    expect($result)->toBe([1 => 100, 2 => 101]);
});

test('does not match when nothing in the roster resembles the nickname', function (): void {
    $player = Player::factory()->make(['id' => 1, 'nickname' => 'Zzyzx']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$player]),
        [['id' => 100, 'displayName' => 'Antonio Sivera']],
    );

    expect($result)->toBe([]);
});
