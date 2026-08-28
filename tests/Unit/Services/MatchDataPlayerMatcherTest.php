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

// Both nicknames below only ever match "Antonio Sivera Llorente" via surnameMatch
// (neither is a first-name prefix, and neither has the "initial. surname" shape),
// so they collide on the SAME single rule pass, over the SAME single roster entry.
// Fixing this required checking the roster-entry side of the ambiguity, not just
// the player side: without that, a naive "first player considered wins" resolution
// would silently link whichever nickname happens to be evaluated first — which is
// exactly what made this order-dependent before the fix. Neither player has any
// other candidate, so neither should ever be linked, in either input order.
test('leaves both players unresolved when they are the only two candidates for the same roster entry (order A)', function (): void {
    $sivera = Player::factory()->make(['id' => 1, 'nickname' => 'Sivera']);
    $llorente = Player::factory()->make(['id' => 2, 'nickname' => 'Llorente']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$sivera, $llorente]),
        [['id' => 100, 'displayName' => 'Antonio Sivera Llorente']],
    );

    expect($result)->toBe([]);
});

test('leaves both players unresolved when they are the only two candidates for the same roster entry (order B)', function (): void {
    $sivera = Player::factory()->make(['id' => 1, 'nickname' => 'Sivera']);
    $llorente = Player::factory()->make(['id' => 2, 'nickname' => 'Llorente']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$llorente, $sivera]),
        [['id' => 100, 'displayName' => 'Antonio Sivera Llorente']],
    );

    expect($result)->toBe([]);
});

test('resolves an unrelated bystander alongside a cross-rule cascade in the same call', function (): void {
    // Same cascade as the "tighter rule frees a looser match" test above (A. García
    // resolves under initialAndSurnameMatch, which frees "Andrés García" so bare
    // "García" can resolve under surnameMatch on a later rule), plus a third player
    // whose nickname never contends for either García entry — proving the fixed-point
    // loop and a genuinely unrelated player don't interfere with each other.
    $exact = Player::factory()->make(['id' => 1, 'nickname' => 'A. García']);
    $surnameOnly = Player::factory()->make(['id' => 2, 'nickname' => 'García']);
    $bystander = Player::factory()->make(['id' => 3, 'nickname' => 'Bartra']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$exact, $surnameOnly, $bystander]),
        [
            ['id' => 100, 'displayName' => 'Andrés García'],
            ['id' => 101, 'displayName' => 'Kike García'],
            ['id' => 102, 'displayName' => 'Marc Bartra'],
        ],
    );

    expect($result)->toBe([1 => 100, 2 => 101, 3 => 102]);
});

test('commits every unambiguous pair found in a single rule round together', function (): void {
    // Three players, three roster entries, no contention between any of them —
    // each only ever matches its own entry under surnameMatch. All three should
    // resolve out of the very first round of that rule, confirming a round commits
    // every safe pair it finds at once rather than one at a time.
    $andres = Player::factory()->make(['id' => 1, 'nickname' => 'Xx Andrés']);
    $kike = Player::factory()->make(['id' => 2, 'nickname' => 'Yy Kike']);
    $bartra = Player::factory()->make(['id' => 3, 'nickname' => 'Bartra']);

    $result = (new MatchDataPlayerMatcher)->match(
        new Collection([$andres, $kike, $bartra]),
        [
            ['id' => 100, 'displayName' => 'Andrés García'],
            ['id' => 101, 'displayName' => 'Kike García'],
            ['id' => 102, 'displayName' => 'Marc Bartra'],
        ],
    );

    expect($result)->toBe([1 => 100, 2 => 101, 3 => 102]);
});
