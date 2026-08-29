# Fitxa de Partit Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the fitxa de partit's two-column player list with a horizontal tactical pitch (real lineups from worldcup26), a tabbed block (Suplentes / Datos del partido / Cronología), and an updated player-detail modal — all built against the phase-3 `fixture_lineups`/`fixture_events` data.

**Architecture:** Backend computes and ships flat, display-ready props (pitch x/y coordinates, per-player event counts pulled out of the `stats` JSON blob, team-stat sums) from `FixturesController::show()` — the frontend only renders. Two small derived enums (`MatchPositionLine`, `MatchPositionSide`) classify worldcup26's free-text `position` for pitch placement, without storing anything new. A shared `HqLineupPlayerToken` component (avatar + rectangular overlay badges) is reused by both the pitch (absolutely positioned) and the bench (row layout).

**Tech Stack:** Laravel 12 + Pest (backend), Inertia + React 19 + TypeScript + Tailwind v4 (frontend). This project has no JS test runner — frontend correctness is verified by `npm run build` (type-check) and a manual browser check per the `run` skill, not automated tests.

**Spec:** `docs/superpowers/specs/2026-08-29-fitxa-partit-redesign-design.md`

## Global Constraints

- Source of truth per data point (do not mix): campo/banquillo/cronología event badges (gol/asistencia/tarjetas) → worldcup26 (`fixture_lineups.stats`, `fixture_events`). Points, DAZN, and the position badge (POR/DEF/MED/DEL) → always LaLiga Fantasy (`PlayerScore`, `Player.position`), everywhere including the modal. The player modal's full stat grid → 100% Fantasy (`PlayerScore.stats`), **except** the sub-in/out minute badge, which stays worldcup26 (`FixtureLineup.sub_minute`) even inside the modal.
- The 3 penalty stats worldcup26 doesn't have (provocado/cometido/parado) stay Fantasy-only, unchanged from today's modal.
- Overlay badges on a player avatar (position, sub, good/bad events) are **rectangles**, never circles — reuse `HqPositionTag`'s existing look, don't invent a new shape language.
- Good/bad corner badges expand **outward** (away from the avatar center) when they hold more than one icon — pin the near edge to the avatar, let the far edge grow, never let them overlap each other or the photo.
- Corner badge backgrounds are **solid**, not translucent (unlike most of this app's `bg-hq-*/10`-style tints).
- `fixture_lineups.team_id` is always the match-time team, never `Player.team_id` (already true from phase 3 — don't regress it).
- Pitch coordinates are computed server-side in the controller, not in the frontend — this project has no JS test runner, so anything that needs a test lives in PHP.

---

### Task 1: `fixture_lineups.player_id` nullable + stop skipping unresolved athletes

**Files:**
- Create: `database/migrations/2026_08_30_090000_make_fixture_lineups_player_id_nullable.php`
- Modify: `app/Console/Commands/SyncLiveSeasonMatchData.php`
- Modify: `app/Models/FixtureLineup.php`
- Modify: `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`

**Interfaces:**
- Produces: `fixture_lineups` rows can now have `player_id: null` (roster entries worldcup26 sent but that never resolved to a local `Player`) — carrying `team_id`, `starter`, `position`, `jersey`, `stats` regardless.

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->foreignId('player_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->foreignId('player_id')->nullable(false)->change();
        });
    }
};
```

Run: `php artisan migrate`
Expected: applies cleanly. Note: changing column nullability with `->change()` requires `doctrine/dbal` — check `composer.json` for it first; if missing, run `composer require doctrine/dbal --dev` before migrating (this is a dev-time schema tool, not a runtime dependency).

- [ ] **Step 2: Update the model docblock/cast for the now-nullable column**

In `app/Models/FixtureLineup.php`, change the docblock line `@property-read int $player_id` to `@property-read int|null $player_id`.

- [ ] **Step 3: Write the failing test — unresolved athlete gets a row with `player_id: null`**

Replace the existing `'running twice with the same payload does not duplicate lineups, and reports unresolved players'` test in `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php` with:

```php
test('creates a fixture_lineups row with a null player_id for an unresolved athlete, and reports it', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subMinutes(30),
    ]);
    $known = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Known'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1', 'stats' => [['name' => 'saves', 'value' => 1]]],
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5', 'stats' => [['name' => 'foulsCommitted', 'value' => 2]]],
                ],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutputToContain('Unknown Player')
        ->assertSuccessful();

    expect(FixtureLineup::query()->where('player_id', $known->id)->sole()->stats)
        ->toBe([['name' => 'saves', 'value' => 1]]);

    $unresolvedRow = FixtureLineup::query()->whereNull('player_id')->where('fixture_id', $fixture->id)->sole();
    expect($unresolvedRow->jersey)->toBe('5')
        ->and($unresolvedRow->position)->toBe('CB')
        ->and($unresolvedRow->team_id)->toBe($home->id)
        ->and($unresolvedRow->stats)->toBe([['name' => 'foulsCommitted', 'value' => 2]]);

    // Second sync with the same payload: the known player's row updates in place (still 1
    // row), and the unresolved row is replaced, not duplicated (still 1 row with null).
    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    expect(FixtureLineup::query()->where('player_id', $known->id)->count())->toBe(1)
        ->and(FixtureLineup::query()->whereNull('player_id')->where('fixture_id', $fixture->id)->count())->toBe(1);
});
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: FAIL — no `fixture_lineups` row exists for the unresolved athlete (the current code `continue`s past it).

- [ ] **Step 5: Stop skipping unresolved athletes**

In `app/Console/Commands/SyncLiveSeasonMatchData.php`, replace `syncLineups()` and add the delete-then-recreate step for unresolved rows:

```php
    /**
     * @param  array<string, mixed>  $event
     * @return list<string>
     */
    private function syncLineups(Fixture $fixture, array $event): array
    {
        $rosters = is_array($event['rosters'] ?? null) ? $event['rosters'] : [];
        $unresolved = [];

        FixtureLineup::query()->where('fixture_id', $fixture->id)->whereNull('player_id')->delete();

        foreach ($rosters as $rosterEntry) {
            $teamMatchDataId = (int) ($rosterEntry['team']['id'] ?? 0);
            $team = Team::query()->where('match_data_id', $teamMatchDataId)->first();

            if ($team === null) {
                continue;
            }

            $rosterPlayers = is_array($rosterEntry['roster'] ?? null) ? $rosterEntry['roster'] : [];

            foreach ($rosterPlayers as $rosterPlayer) {
                $athleteMatchDataId = (int) ($rosterPlayer['athlete']['id'] ?? 0);
                $player = Player::query()
                    ->where('match_data_id', $athleteMatchDataId)
                    ->first();

                if ($player === null) {
                    $unresolved[] = (string) ($rosterPlayer['athlete']['displayName'] ?? $athleteMatchDataId);
                    $this->createUnresolvedLineup($fixture, $team, $rosterPlayer);

                    continue;
                }

                $this->upsertLineup($fixture, $team, $player, $rosterPlayer);
            }
        }

        return $unresolved;
    }

    /**
     * @param  array<string, mixed>  $rosterPlayer
     */
    private function createUnresolvedLineup(Fixture $fixture, Team $team, array $rosterPlayer): void
    {
        FixtureLineup::query()->create([
            'fixture_id' => $fixture->id,
            'player_id' => null,
            'team_id' => $team->id,
            'starter' => (bool) ($rosterPlayer['starter'] ?? false),
            'position' => (string) ($rosterPlayer['position']['displayName'] ?? ''),
            'jersey' => (string) ($rosterPlayer['jersey'] ?? ''),
            'subbed_in' => (bool) ($rosterPlayer['subbedIn'] ?? false),
            'subbed_out' => (bool) ($rosterPlayer['subbedOut'] ?? false),
            'counterpart_player_id' => null,
            'sub_minute' => $this->subMinute($rosterPlayer),
            'stats' => is_array($rosterPlayer['stats'] ?? null) ? $rosterPlayer['stats'] : [],
        ]);
    }
```

Note: `createUnresolvedLineup()` doesn't resolve `counterpart_player_id` even if the roster entry has `subbedInFor`/`subbedOutFor` — an unresolved player's counterpart is out of scope for this fix (the pitch only needs `sub_minute` to show the sustitución badge on an unresolved token; it never shows "por {nombre}" for a hueco).

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: PASS

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions (aside from the 3 pre-existing unrelated `FixturesControllerTest` Vite-manifest failures already known from phase 3 — not this task's concern).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_30_090000_make_fixture_lineups_player_id_nullable.php app/Console/Commands/SyncLiveSeasonMatchData.php app/Models/FixtureLineup.php tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php
git commit -m "feat: store unresolved-athlete lineup rows instead of skipping them"
```

---

### Task 2: `MatchPositionLine` and `MatchPositionSide` enums

**Files:**
- Create: `app/Enums/MatchPositionLine.php`
- Create: `app/Enums/MatchPositionSide.php`
- Test: `tests/Feature/Models/MatchPositionTest.php`

**Interfaces:**
- Produces: `MatchPositionLine::fromWorldcup26Text(string $text): self` with cases `Goalkeeper | Defender | Midfielder | Forward | Substitute | Unknown`. `MatchPositionSide::fromWorldcup26Text(string $text): self` with cases `Left | Center | Right`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;

test('classifies worldcup26 position text into a pitch line', function (string $text, MatchPositionLine $line): void {
    expect(MatchPositionLine::fromWorldcup26Text($text))->toBe($line);
})->with([
    ['Goalkeeper', MatchPositionLine::Goalkeeper],
    ['Center Right Defender', MatchPositionLine::Defender],
    ['Left Back', MatchPositionLine::Defender],
    ['Right Back', MatchPositionLine::Defender],
    ['Center Midfielder', MatchPositionLine::Midfielder],
    ['Right Midfielder', MatchPositionLine::Midfielder],
    ['Center Left Forward', MatchPositionLine::Forward],
    ['Substitute', MatchPositionLine::Substitute],
    ['Something Unseen', MatchPositionLine::Unknown],
]);

test('classifies worldcup26 position text into a pitch side', function (string $text, MatchPositionSide $side): void {
    expect(MatchPositionSide::fromWorldcup26Text($text))->toBe($side);
})->with([
    ['Center Right Defender', MatchPositionSide::Right],
    ['Left Back', MatchPositionSide::Left],
    ['Center Left Forward', MatchPositionSide::Left],
    ['Center Midfielder', MatchPositionSide::Center],
    ['Goalkeeper', MatchPositionSide::Center],
]);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MatchPositionTest`
Expected: FAIL — the enum classes don't exist.

- [ ] **Step 3: Write the enums**

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchPositionLine: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case Midfielder = 'midfielder';
    case Forward = 'forward';
    case Substitute = 'substitute';
    case Unknown = 'unknown';

    public static function fromWorldcup26Text(string $text): self
    {
        return match (true) {
            str_contains($text, 'Goalkeeper') => self::Goalkeeper,
            str_contains($text, 'Back') || str_contains($text, 'Defender') => self::Defender,
            str_contains($text, 'Midfielder') => self::Midfielder,
            str_contains($text, 'Forward') => self::Forward,
            $text === 'Substitute' => self::Substitute,
            default => self::Unknown,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchPositionSide: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    public static function fromWorldcup26Text(string $text): self
    {
        return match (true) {
            str_contains($text, 'Left') => self::Left,
            str_contains($text, 'Right') => self::Right,
            default => self::Center,
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=MatchPositionTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/MatchPositionLine.php app/Enums/MatchPositionSide.php tests/Feature/Models/MatchPositionTest.php
git commit -m "feat: classify worldcup26 position text into pitch line/side enums"
```

---

### Task 3: `FixturesController::show()` — lineups, events, and team-stats props

**Files:**
- Modify: `app/Http/Controllers/FixturesController.php`
- Test: `tests/Feature/Http/Controllers/FixturesControllerTest.php`

**Interfaces:**
- Consumes: `MatchPositionLine`/`MatchPositionSide` (Task 2), `FixtureLineup`/`FixtureEvent` (existing models), `PlayerScore` (existing, for points/dazn — same `$scores` query already in this method).
- Produces: three new Inertia props on `fixtures/show` — `lineups: array<{id, player, team_id, starter, position, jersey, subbed_in, subbed_out, sub_minute, counterpart_player, goals, assists, yellow_cards, red_cards, points, dazn_points, x, y}>`, `events: array<{id, minute, type, team_id, is_own_goal, is_penalty, player}>`, `team_stats: array<{label, local, guest}>`. The existing `fixture`, `weekFixtures`, `scores` props are unchanged.

**Pitch coordinate constants** (per team side, per line — mirror for guest):

| línea | x local | x guest |
|---|---|---|
| Goalkeeper | 6 | 94 |
| Defender | 20 | 80 |
| Midfielder | 36 | 64 |
| Forward | 46 | 54 |

y: within a line, evenly spaced from 12 to 88 ordered `Left, Center, Right` (ties broken by jersey number ascending, for determinism).

- [ ] **Step 1: Write the failing test — lineups prop with coordinates and stats extraction**

Append to `tests/Feature/Http/Controllers/FixturesControllerTest.php`:

```php
test('includes lineups with pitch coordinates, event counts, points and dazn', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $home = $fixture->localTeam;
    $player = Player::factory()->create(['team_id' => $home->id, 'position' => PlayerPosition::Defender]);

    \App\Models\FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'starter' => true,
        'position' => 'Left Back',
        'jersey' => '3',
        'stats' => [
            ['name' => 'totalGoals', 'value' => 1],
            ['name' => 'goalAssists', 'value' => 0],
            ['name' => 'yellowCards', 'value' => 1],
            ['name' => 'redCards', 'value' => 0],
        ],
    ]);
    PlayerScore::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'points' => 4,
        'stats' => ['marca_points' => [3, 0]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 1)
        ->where('lineups.0.player.id', $player->id)
        ->where('lineups.0.position', 'Left Back')
        ->where('lineups.0.jersey', '3')
        ->where('lineups.0.goals', 1)
        ->where('lineups.0.assists', 0)
        ->where('lineups.0.yellow_cards', 1)
        ->where('lineups.0.red_cards', 0)
        ->where('lineups.0.points', 4)
        ->where('lineups.0.dazn_points', 0)
        ->where('lineups.0.x', 20)
    );
});

test('includes a null player for an unresolved lineup entry, with no points/dazn', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    \App\Models\FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => null,
        'team_id' => $fixture->localTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 1)
        ->where('lineups.0.player', null)
        ->where('lineups.0.points', null)
        ->where('lineups.0.dazn_points', null)
        ->where('lineups.0.x', 6)
    );
});

test('mirrors x coordinates for the guest team', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    \App\Models\FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $fixture->guestTeam->id]),
        'team_id' => $fixture->guestTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.x', 94)
    );
});

test('includes events with the player relation, ordered by minute', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $scorer = Player::factory()->create(['team_id' => $fixture->localTeam->id]);

    \App\Models\FixtureEvent::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $fixture->localTeam->id, 'player_id' => $scorer->id, 'type' => 'goal', 'minute' => 73]);
    \App\Models\FixtureEvent::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $fixture->guestTeam->id, 'player_id' => null, 'type' => 'yellow_card', 'minute' => 12]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('events', 2)
        ->where('events.0.minute', 12)
        ->where('events.0.player', null)
        ->where('events.1.minute', 73)
        ->where('events.1.player.id', $scorer->id)
    );
});

test('sums fixture_lineups stats into team_stats by team', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    \App\Models\FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $fixture->localTeam->id]),
        'team_id' => $fixture->localTeam->id,
        'stats' => [['name' => 'totalShots', 'value' => 4], ['name' => 'shotsOnTarget', 'value' => 2]],
    ]);
    \App\Models\FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $fixture->guestTeam->id]),
        'team_id' => $fixture->guestTeam->id,
        'stats' => [['name' => 'totalShots', 'value' => 9]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('team_stats.1.label', 'Tiros totales')
        ->where('team_stats.1.local', 4)
        ->where('team_stats.1.guest', 9)
        ->where('team_stats.0.label', 'Tiros a puerta')
        ->where('team_stats.0.local', 2)
        ->where('team_stats.0.guest', 0)
    );
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=FixturesControllerTest`
Expected: FAIL — `lineups`/`events`/`team_stats` props don't exist yet.

- [ ] **Step 3: Implement the controller changes**

In `app/Http/Controllers/FixturesController.php`, add these imports:

```php
use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use Illuminate\Support\Collection;
```

Add these private constants and methods to the class, and extend `show()`:

```php
    /** @var array<string, array{local: int, guest: int}> */
    private const array PITCH_LINE_DEPTH = [
        'goalkeeper' => ['local' => 6, 'guest' => 94],
        'defender' => ['local' => 20, 'guest' => 80],
        'midfielder' => ['local' => 36, 'guest' => 64],
        'forward' => ['local' => 46, 'guest' => 54],
    ];

    /** @var array<string, string> */
    private const array TEAM_STAT_LABELS = [
        'shotsOnTarget' => 'Tiros a puerta',
        'totalShots' => 'Tiros totales',
        'foulsCommitted' => 'Faltas cometidas',
        'saves' => 'Paradas',
        'goalAssists' => 'Asistencias',
        'yellowCards' => 'Tarjetas amarillas',
    ];
```

```php
    public function show(Fixture $fixture): Response
    {
        $fixture->load(['localTeam', 'guestTeam']);

        $weekFixtures = Fixture::query()
            ->where('season_id', $fixture->season_id)
            ->where('week_number', $fixture->week_number)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get();

        $scores = $fixture->playerScores()
            ->whereHas('player.seasons', fn ($query) => $query
                ->where('season_id', $fixture->season_id)
                ->where('position', '!=', PlayerPosition::Coach))
            ->with(['player', 'team'])
            ->get();

        $this->attachCurrentSeason($scores->pluck('player'), $fixture->season_id);

        $scores = $scores
            ->sortByDesc('points')
            ->sortBy(fn ($score): int => self::POSITION_ORDER[$score->player->position->value])
            ->values();

        $lineupManagersByPlayer = ManagerLineupPlayer::query()
            ->whereIn('player_id', $scores->pluck('player_id'))
            ->whereHas('lineup', fn ($query) => $query
                ->where('week_number', $fixture->week_number)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $fixture->season_id)))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('player_id');

        $scores->each(function (PlayerScore $score) use ($lineupManagersByPlayer): void {
            $score->lineup_manager = $lineupManagersByPlayer->get($score->player_id)?->lineup?->seasonManager;
        });

        $scoresByPlayerId = $scores->keyBy('player_id');

        $lineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->with('player.team', 'counterpartPlayer')
            ->get()
            ->map(fn (FixtureLineup $lineup): array => $this->presentLineup($lineup, $fixture, $scoresByPlayerId));

        $events = FixtureEvent::query()
            ->where('fixture_id', $fixture->id)
            ->with('player.team')
            ->orderBy('minute')
            ->get()
            ->map(fn (FixtureEvent $event): array => [
                'id' => $event->id,
                'minute' => $event->minute,
                'type' => $event->type,
                'team_id' => $event->team_id,
                'is_own_goal' => $event->is_own_goal,
                'is_penalty' => $event->is_penalty,
                'player' => $event->player,
            ]);

        return Inertia::render('fixtures/show', [
            'fixture' => $fixture,
            'weekFixtures' => $weekFixtures,
            'scores' => $scores,
            'lineups' => $lineups,
            'events' => $events,
            'team_stats' => $this->teamStats($fixture),
        ]);
    }

    /**
     * @param  Collection<int, PlayerScore>  $scoresByPlayerId
     * @return array<string, mixed>
     */
    private function presentLineup(FixtureLineup $lineup, Fixture $fixture, Collection $scoresByPlayerId): array
    {
        $score = $lineup->player_id !== null ? $scoresByPlayerId->get($lineup->player_id) : null;
        $isLocal = $lineup->team_id === $fixture->team_local_id;

        return [
            'id' => $lineup->id,
            'player' => $lineup->player,
            'team_id' => $lineup->team_id,
            'starter' => $lineup->starter,
            'position' => $lineup->position,
            'jersey' => $lineup->jersey,
            'subbed_in' => $lineup->subbed_in,
            'subbed_out' => $lineup->subbed_out,
            'sub_minute' => $lineup->sub_minute,
            'counterpart_player' => $lineup->counterpartPlayer,
            'goals' => $this->statValue($lineup->stats, 'totalGoals'),
            'assists' => $this->statValue($lineup->stats, 'goalAssists'),
            'yellow_cards' => $this->statValue($lineup->stats, 'yellowCards'),
            'red_cards' => $this->statValue($lineup->stats, 'redCards'),
            'points' => $score?->points,
            'dazn_points' => $score?->stats['marca_points'][1] ?? null,
            'x' => $lineup->starter ? $this->pitchX($lineup->position, $isLocal) : null,
            'y' => $lineup->starter ? $this->pitchY($lineup, $fixture) : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $stats
     */
    private function statValue(array $stats, string $name): int
    {
        foreach ($stats as $stat) {
            if (($stat['name'] ?? null) === $name) {
                return (int) ($stat['value'] ?? 0);
            }
        }

        return 0;
    }

    private function pitchX(string $position, bool $isLocal): float
    {
        $line = MatchPositionLine::fromWorldcup26Text($position);
        $depths = self::PITCH_LINE_DEPTH[$line->value] ?? self::PITCH_LINE_DEPTH['midfielder'];

        return (float) ($isLocal ? $depths['local'] : $depths['guest']);
    }

    private function pitchY(FixtureLineup $lineup, Fixture $fixture): float
    {
        $line = MatchPositionLine::fromWorldcup26Text($lineup->position);

        $lineupMates = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->where('team_id', $lineup->team_id)
            ->where('starter', true)
            ->get()
            ->filter(fn (FixtureLineup $mate): bool => MatchPositionLine::fromWorldcup26Text($mate->position) === $line)
            ->sortBy([
                fn (FixtureLineup $a, FixtureLineup $b): int => $this->sideOrder($a->position) <=> $this->sideOrder($b->position),
                fn (FixtureLineup $a, FixtureLineup $b): int => $a->jersey <=> $b->jersey,
            ])
            ->values();

        $index = $lineupMates->search(fn (FixtureLineup $mate): bool => $mate->id === $lineup->id);
        $count = $lineupMates->count();

        if ($count <= 1) {
            return 50.0;
        }

        $index = $index === false ? 0 : $index;

        return round(12 + ($index * (76 / ($count - 1))), 1);
    }

    /**
     * @return list<array{label: string, local: int, guest: int}>
     */
    private function teamStats(Fixture $fixture): array
    {
        $lineups = FixtureLineup::query()->where('fixture_id', $fixture->id)->get();

        return collect(self::TEAM_STAT_LABELS)
            ->map(function (string $label, string $key) use ($lineups, $fixture): array {
                $local = $lineups->where('team_id', $fixture->team_local_id)
                    ->sum(fn (FixtureLineup $lineup): int => $this->statValue($lineup->stats, $key));
                $guest = $lineups->where('team_id', $fixture->team_guest_id)
                    ->sum(fn (FixtureLineup $lineup): int => $this->statValue($lineup->stats, $key));

                return ['label' => $label, 'local' => $local, 'guest' => $guest];
            })
            ->values()
            ->all();
    }

    /**
     * Explicit left-to-right numeric order for sorting, since comparing
     * `MatchPositionSide::value` directly would sort lexically
     * ("center" < "left" < "right"), not the left-to-right order the pitch needs.
     */
    private function sideOrder(string $position): int
    {
        return match (MatchPositionSide::fromWorldcup26Text($position)) {
            MatchPositionSide::Left => 0,
            MatchPositionSide::Center => 1,
            MatchPositionSide::Right => 2,
        };
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=FixturesControllerTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS (aside from the 3 known pre-existing Vite-manifest failures).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/FixturesController.php tests/Feature/Http/Controllers/FixturesControllerTest.php
git commit -m "feat: add lineups, events and team_stats props to the fixture show page"
```

---

### Task 4: TypeScript types for the new props

**Files:**
- Modify: `resources/js/types/models.ts`

**Interfaces:**
- Produces: `FixtureLineupEntry`, `FixtureEventEntry`, `FixtureTeamStat` interfaces, matching Task 3's JSON shape exactly.

- [ ] **Step 1: Add the types**

In `resources/js/types/models.ts`, add after the `Fixture` interface:

```ts
export interface FixtureLineupEntry {
    id: number;
    player: Player | null;
    team_id: number;
    starter: boolean;
    position: string;
    jersey: string;
    subbed_in: boolean;
    subbed_out: boolean;
    sub_minute: number | null;
    counterpart_player: Player | null;
    goals: number;
    assists: number;
    yellow_cards: number;
    red_cards: number;
    points: number | null;
    dazn_points: number | null;
    x: number | null;
    y: number | null;
}

export type FixtureEventType =
    'goal' | 'yellow_card' | 'red_card' | 'penalty_missed';

export interface FixtureEventEntry {
    id: number;
    minute: number;
    type: FixtureEventType;
    team_id: number;
    is_own_goal: boolean;
    is_penalty: boolean;
    player: Player | null;
}

export interface FixtureTeamStat {
    label: string;
    local: number;
    guest: number;
}
```

- [ ] **Step 2: Type-check**

Run: `npm run build` (or `npx tsc --noEmit` if faster — check `package.json` `scripts` for the exact type-check command)
Expected: no new type errors (these are new, unused-so-far types — should be a clean pass).

- [ ] **Step 3: Commit**

```bash
git add resources/js/types/models.ts
git commit -m "feat: add TypeScript types for fixture lineups/events/team-stats"
```

---

### Task 5: `HqLineupPlayerToken` — shared avatar + badges component

**Files:**
- Create: `resources/js/components/hq-lineup-player-token.tsx`
- Modify: `resources/css/app.css` (two new solid corner-badge background tokens)

**Interfaces:**
- Consumes: `FixtureLineupEntry` (Task 4), `HqPositionTag` (existing, `resources/js/components/hq-position-tag.tsx`), `matchPointsBadgeClass`/`daznPointsBadgeClass` (existing, `resources/js/lib/points.ts`).
- Produces: `HqLineupPlayerToken` component, props `{ entry: FixtureLineupEntry; variant: 'pitch' | 'bench' }`. Used by Tasks 6 and 7.

- [ ] **Step 1: Add the two solid corner-badge background tokens**

In `resources/css/app.css`, inside the `@theme { ... }` block, alongside the other `--color-hq-*` tokens, add:

```css
    --color-hq-good-corner: #1f2e0d;
    --color-hq-bad-corner: #2e0f14;
```

- [ ] **Step 2: Write the component**

```tsx
import { User } from 'lucide-react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { daznPointsBadgeClass, matchPointsBadgeClass } from '@/lib/points';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry } from '@/types/models';

interface HqLineupPlayerTokenProps {
    entry: FixtureLineupEntry;
    variant: 'pitch' | 'bench';
    /** Omitted entirely for an unresolved token (no player to show anything for). */
    onSelect?: (entry: FixtureLineupEntry) => void;
}

const AVATAR_SIZE: Record<'pitch' | 'bench', string> = {
    pitch: 'h-13 w-13', // 52px
    bench: 'h-8.5 w-8.5', // 34px
};

export function HqLineupPlayerToken({ entry, variant, onSelect }: HqLineupPlayerTokenProps) {
    const isPitch = variant === 'pitch';
    const clickable = entry.player !== null && onSelect !== undefined;
    const handleClick = clickable ? () => onSelect(entry) : undefined;
    const hasGoodEvent = entry.goals > 0 || entry.assists > 0;
    const hasBadEvent = entry.yellow_cards > 0 || entry.red_cards > 0;
    const subMinute = entry.subbed_in || entry.subbed_out ? entry.sub_minute : null;

    // Icon content only — positioning/background differ between the pitch
    // (pegged to the avatar corner) and the bench (its own strip below the
    // name, since the bench row has room to spare and the pitch token doesn't).
    const goodIcons = (
        <>
            {Array.from({ length: entry.goals }, (_, i) => (
                <span key={`g-${i}`}>⚽</span>
            ))}
            {Array.from({ length: entry.assists }, (_, i) => (
                <span key={`a-${i}`}>➜</span>
            ))}
        </>
    );
    const badIcons = (
        <>
            {Array.from({ length: entry.yellow_cards }, (_, i) => (
                <span key={`y-${i}`} className="inline-block h-2.5 w-[7px] rounded-[1px] bg-hq-gold" />
            ))}
            {Array.from({ length: entry.red_cards }, (_, i) => (
                <span key={`r-${i}`} className="inline-block h-2.5 w-[7px] rounded-[1px] bg-hq-live" />
            ))}
        </>
    );

    const avatar = (
        <div className={cn('relative shrink-0', AVATAR_SIZE[variant])}>
            {isPitch && hasBadEvent && (
                <span className="absolute -top-1.5 right-8 z-10 flex items-center gap-0.5 whitespace-nowrap border border-hq-live bg-hq-bad-corner px-1 py-px font-mono text-[9px] font-bold text-hq-live">
                    {badIcons}
                </span>
            )}
            {isPitch && hasGoodEvent && (
                <span className="absolute -top-1.5 left-8 z-10 flex items-center gap-0.5 whitespace-nowrap border border-hq-lime bg-hq-good-corner px-1 py-px font-mono text-[9px] font-bold text-hq-lime">
                    {goodIcons}
                </span>
            )}

            {entry.player ? (
                <EntityImage
                    src={entry.player.image}
                    alt={entry.player.nickname}
                    fallback={User}
                    className={cn(AVATAR_SIZE[variant], 'border-1.5 border-hq-border-strong bg-hq-border')}
                />
            ) : (
                <div
                    className={cn(
                        AVATAR_SIZE[variant],
                        'flex items-center justify-center rounded-full border-1.5 border-dashed border-hq-border-strong font-mono text-hq-moss-dim',
                    )}
                >
                    ?
                </div>
            )}

            {entry.player && (
                <HqPositionTag
                    position={entry.player.position}
                    className="absolute -bottom-1 -left-2 z-10 bg-hq-ink"
                />
            )}

            {subMinute !== null && (
                <span
                    className={cn(
                        'absolute -right-2 -bottom-1 z-10 whitespace-nowrap border bg-hq-ink px-1 py-px font-mono text-[8px] font-bold',
                        entry.subbed_out ? 'border-hq-live text-hq-live' : 'border-hq-lime text-hq-lime',
                    )}
                >
                    ↳{subMinute}
                </span>
            )}
        </div>
    );

    const nameLine = (
        <div className={cn('truncate font-mono', isPitch ? 'text-center text-[13px]' : 'text-[12.5px]')}>
            <b className="mr-1 text-hq-lime">{entry.jersey}</b>
            {entry.player?.nickname ?? 'No vinculado'}
        </div>
    );

    const statBadges = entry.player && entry.points !== null && (
        <div className={cn('flex items-center gap-1.5', isPitch && 'justify-center')}>
            <span className={cn('rounded-[2px] px-2 py-0.5 font-mono text-[13px] font-bold', matchPointsBadgeClass(entry.points))}>
                {entry.points}
            </span>
            {entry.dazn_points !== null && (
                <span className={cn('flex items-center gap-1 rounded-[2px] bg-hq-border px-1.5 py-0.5 font-mono text-[11px]', daznPointsBadgeClass(entry.dazn_points))}>
                    <img src="/images/dazn-logo.png" alt="DAZN" className="h-3.5 w-3.5" />
                    {entry.dazn_points}
                </span>
            )}
        </div>
    );

    // Bench only: good/bad events get their own strip below the name instead
    // of overlaying the photo — the bench row has the horizontal room the
    // tight pitch token doesn't, so there's no need to cram icons onto the avatar.
    const benchEventStrip = !isPitch && (hasGoodEvent || hasBadEvent) && (
        <div className="mt-0.5 flex items-center gap-1.5">
            {hasGoodEvent && (
                <span className="flex items-center gap-0.5 border border-hq-lime bg-hq-good-corner px-1.5 py-px font-mono text-[9px] font-bold text-hq-lime">
                    {goodIcons}
                </span>
            )}
            {hasBadEvent && (
                <span className="flex items-center gap-0.5 border border-hq-live bg-hq-bad-corner px-1.5 py-px font-mono text-[9px] font-bold text-hq-live">
                    {badIcons}
                </span>
            )}
        </div>
    );

    if (isPitch) {
        return (
            <div
                className={cn('flex w-31 flex-col items-center gap-0.5', clickable && 'cursor-pointer')}
                onClick={handleClick}
            >
                {avatar}
                {nameLine}
                {statBadges}
            </div>
        );
    }

    return (
        <div className={cn('flex items-center gap-2.5 px-3 py-1.5', clickable && 'cursor-pointer')} onClick={handleClick}>
            {avatar}
            <div className="min-w-0 flex-1">
                {nameLine}
                {!entry.player && <div className="font-mono text-[9.5px] text-hq-moss-dim">no vinculado</div>}
                {benchEventStrip}
                {statBadges}
            </div>
        </div>
    );
}
```

Note: `daznPointsBadgeClass` returns a `bg-*/text-*` pair meant for a standalone badge (see `resources/js/lib/points.ts`) — applying it on top of the fixed `bg-hq-border` here means its `bg-*` half is overridden by the later class in the string (Tailwind resolves conflicting utilities by source order, and `daznPointsBadgeClass`'s classes come after `bg-hq-border` in the template literal via `cn`, so its background wins) — this is intentional, matching how `matchPointsBadgeClass` is used elsewhere in this codebase (as a full badge background, not just a text color).

- [ ] **Step 3: Type-check**

Run: `npm run build`
Expected: passes (component isn't wired into a page yet, but must type-check standalone).

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/hq-lineup-player-token.tsx resources/css/app.css
git commit -m "feat: add HqLineupPlayerToken, shared avatar+badges for pitch and bench"
```

---

### Task 6: `HqMatchPitch` component

**Files:**
- Create: `resources/js/components/hq-match-pitch.tsx`

**Interfaces:**
- Consumes: `HqLineupPlayerToken` (Task 5), `FixtureLineupEntry[]` (Task 4).
- Produces: `HqMatchPitch` component, props `{ lineups: FixtureLineupEntry[] }`. Filters to `starter === true` internally (bench is Task 7's job). Used by Task 8.

- [ ] **Step 1: Write the component**

```tsx
import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import type { FixtureLineupEntry } from '@/types/models';

interface HqMatchPitchProps {
    lineups: FixtureLineupEntry[];
    onSelect?: (entry: FixtureLineupEntry) => void;
}

export function HqMatchPitch({ lineups, onSelect }: HqMatchPitchProps) {
    const starters = lineups.filter((entry) => entry.starter && entry.x !== null && entry.y !== null);

    return (
        <div className="relative aspect-16/9.4 w-full overflow-hidden border border-hq-border-strong bg-[#141c0f]">
            <div className="pointer-events-none absolute inset-3.5 border border-hq-lime/20" />
            <div className="pointer-events-none absolute top-3.5 bottom-3.5 left-1/2 w-px -translate-x-1/2 bg-hq-lime/20" />
            <div className="pointer-events-none absolute top-1/2 left-1/2 aspect-square w-[15%] -translate-x-1/2 -translate-y-1/2 rounded-full border border-hq-lime/20" />

            {starters.map((entry) => (
                <div
                    key={entry.id}
                    className="absolute -translate-x-1/2 -translate-y-1/2"
                    style={{ left: `${entry.x}%`, top: `${entry.y}%` }}
                >
                    <HqLineupPlayerToken entry={entry} variant="pitch" onSelect={onSelect} />
                </div>
            ))}
        </div>
    );
}
```

- [ ] **Step 2: Type-check**

Run: `npm run build`
Expected: passes.

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/hq-match-pitch.tsx
git commit -m "feat: add HqMatchPitch tactical pitch component"
```

---

### Task 7: `HqFixtureBench`, `HqFixtureTimeline`, `HqFixtureTeamStats`

**Files:**
- Create: `resources/js/components/hq-fixture-bench.tsx`
- Create: `resources/js/components/hq-fixture-timeline.tsx`
- Create: `resources/js/components/hq-fixture-team-stats.tsx`

**Interfaces:**
- Consumes: `HqLineupPlayerToken` (Task 5), `FixtureLineupEntry`/`FixtureEventEntry`/`FixtureTeamStat`/`Fixture` (Task 4 + existing).
- Produces: three components, used by Task 8.
  - `HqFixtureBench`: props `{ lineups: FixtureLineupEntry[]; localTeamId: number; guestTeamId: number }`.
  - `HqFixtureTimeline`: props `{ events: FixtureEventEntry[]; localTeamId: number }`.
  - `HqFixtureTeamStats`: props `{ stats: FixtureTeamStat[] }`.

- [ ] **Step 1: Write `HqFixtureBench`**

```tsx
import { HqLineupPlayerToken } from '@/components/hq-lineup-player-token';
import { cn } from '@/lib/utils';
import type { FixtureLineupEntry } from '@/types/models';

interface HqFixtureBenchProps {
    lineups: FixtureLineupEntry[];
    localTeamId: number;
    guestTeamId: number;
    onSelect?: (entry: FixtureLineupEntry) => void;
}

function BenchColumn({ entries, onSelect }: { entries: FixtureLineupEntry[]; onSelect?: (entry: FixtureLineupEntry) => void }) {
    return (
        <div className="divide-y divide-hq-border border border-hq-border bg-hq-panel">
            {entries.map((entry) => (
                <div key={entry.id} className={cn(!entry.subbed_in && 'opacity-55')}>
                    <HqLineupPlayerToken entry={entry} variant="bench" onSelect={onSelect} />
                </div>
            ))}
        </div>
    );
}

export function HqFixtureBench({ lineups, localTeamId, guestTeamId, onSelect }: HqFixtureBenchProps) {
    const bench = lineups.filter((entry) => !entry.starter);

    return (
        <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
            <div>
                <p className="mb-1.5 font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                    Banquillo · Local
                </p>
                <BenchColumn entries={bench.filter((entry) => entry.team_id === localTeamId)} onSelect={onSelect} />
            </div>
            <div>
                <p className="mb-1.5 font-mono text-[10px] tracking-wider text-hq-moss-dim uppercase">
                    Banquillo · Visitante
                </p>
                <BenchColumn entries={bench.filter((entry) => entry.team_id === guestTeamId)} onSelect={onSelect} />
            </div>
        </div>
    );
}
```

- [ ] **Step 2: Write `HqFixtureTimeline`**

```tsx
import { cn } from '@/lib/utils';
import type { FixtureEventEntry } from '@/types/models';

const EVENT_ICON: Record<FixtureEventEntry['type'], string> = {
    goal: '⚽',
    yellow_card: '',
    red_card: '',
    penalty_missed: 'P✗',
};

function EventIcon({ event }: { event: FixtureEventEntry }) {
    if (event.type === 'yellow_card' || event.type === 'red_card') {
        return (
            <span
                className={cn(
                    'inline-block h-3 w-2 rounded-[1px]',
                    event.type === 'yellow_card' ? 'bg-hq-gold' : 'bg-hq-live',
                )}
            />
        );
    }

    return <span className="text-xs">{EVENT_ICON[event.type]}</span>;
}

interface HqFixtureTimelineProps {
    events: FixtureEventEntry[];
    localTeamId: number;
}

export function HqFixtureTimeline({ events, localTeamId }: HqFixtureTimelineProps) {
    if (events.length === 0) {
        return (
            <p className="border border-dashed border-hq-border-strong px-4 py-6 text-center font-mono text-[11px] text-hq-moss-dim">
                Sin eventos todavía
            </p>
        );
    }

    return (
        <div className="border border-hq-border bg-hq-panel">
            {events.map((event) => {
                const label = event.player?.nickname ?? 'Sin jugador vinculado';
                const isLocal = event.team_id === localTeamId;

                return (
                    <div key={event.id} className="flex items-center border-b border-hq-border px-3 py-2 text-[12.5px] last:border-b-0">
                        <span className={cn('flex-1 pr-2.5 text-right', isLocal ? 'text-hq-paper' : 'text-hq-moss-dim italic')}>
                            {isLocal ? label : ''}
                        </span>
                        <span className="w-6 text-center">
                            <EventIcon event={event} />
                        </span>
                        <span className="w-8.5 font-mono text-[11px] text-hq-moss">{event.minute}'</span>
                        <span className="w-6" />
                        <span className={cn('flex-1 pl-2.5', !isLocal ? 'text-hq-paper' : 'text-hq-moss-dim italic')}>
                            {!isLocal ? label : ''}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}
```

- [ ] **Step 3: Write `HqFixtureTeamStats`**

```tsx
import type { FixtureTeamStat } from '@/types/models';

interface HqFixtureTeamStatsProps {
    stats: FixtureTeamStat[];
}

export function HqFixtureTeamStats({ stats }: HqFixtureTeamStatsProps) {
    return (
        <div className="border border-hq-border bg-hq-panel px-4 py-3.5">
            {stats.map((stat) => {
                const total = stat.local + stat.guest || 1;
                const localPct = (stat.local / total) * 100;

                return (
                    <div key={stat.label} className="mb-3.5 last:mb-0">
                        <div className="mb-1 flex items-baseline justify-between font-mono text-xs">
                            <span className="font-bold text-hq-lime">{stat.local}</span>
                            <span className="text-[10px] tracking-wide text-hq-moss uppercase">{stat.label}</span>
                            <span className="font-bold text-hq-azure">{stat.guest}</span>
                        </div>
                        <div className="flex h-1.5 overflow-hidden bg-hq-border">
                            <span className="bg-hq-lime" style={{ width: `${localPct}%` }} />
                            <span className="bg-hq-azure" style={{ width: `${100 - localPct}%` }} />
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
```

- [ ] **Step 4: Type-check**

Run: `npm run build`
Expected: passes.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/hq-fixture-bench.tsx resources/js/components/hq-fixture-timeline.tsx resources/js/components/hq-fixture-team-stats.tsx
git commit -m "feat: add bench, timeline and team-stats fixture components"
```

---

### Task 8: Wire it all into `fixtures/show.tsx`, remove `TeamColumn`/`PlayerRow`

**Files:**
- Modify: `resources/js/pages/fixtures/show.tsx`

**Interfaces:**
- Consumes: `HqMatchPitch` (Task 6), `HqFixtureBench`/`HqFixtureTimeline`/`HqFixtureTeamStats` (Task 7), `FixtureLineupEntry`/`FixtureEventEntry`/`FixtureTeamStat` (Task 4), `lineups`/`events`/`team_stats` props (Task 3).

- [ ] **Step 1: Replace the props interface and remove `TeamColumn`/`PlayerRow`**

In `resources/js/pages/fixtures/show.tsx`:

- Add to the imports: `HqMatchPitch` from `@/components/hq-match-pitch`, `HqFixtureBench` from `@/components/hq-fixture-bench`, `HqFixtureTimeline` from `@/components/hq-fixture-timeline`, `HqFixtureTeamStats` from `@/components/hq-fixture-team-stats`, and the types `FixtureEventEntry`, `FixtureLineupEntry`, `FixtureTeamStat` from `@/types/models` (alongside the existing `Fixture`, `PlayerScore` imports).
- Delete the `TeamColumn` and `PlayerRow` function components entirely (both are fully superseded — nothing else calls them).
- Update `FixtureShowProps`:

```tsx
interface FixtureShowProps {
    fixture: Fixture;
    weekFixtures: Fixture[];
    scores: PlayerScore[];
    lineups: FixtureLineupEntry[];
    events: FixtureEventEntry[];
    team_stats: FixtureTeamStat[];
    [key: string]: unknown;
}
```

- [ ] **Step 2: Add the activity legend and tab state**

Add near the top of the `FixtureShow` component body (after the existing `useState` calls):

```tsx
    const [activeTab, setActiveTab] = useState<'bench' | 'stats' | 'timeline'>('bench');
```

- [ ] **Step 3: Replace the player-list section**

Find the block in `FixtureShow`'s JSX that renders (per the "scheduled" ternary) either the "Todavía no hay datos" placeholder or the `sm:hidden` team-switch buttons + `grid grid-cols-1 gap-4 sm:grid-cols-2` of `TeamColumn`s, followed by the "Leyenda de iconos" `LEGEND_ITEMS` block. Replace that entire block (from `{fixture.state === 'scheduled' ? (` through the closing of the `LEGEND_ITEMS` section) with the JSX below. It references `handleSelectLineupEntry`, defined in Step 4 — add Step 4's code to the component body first (right after the `scoresByPlayerId`/`selectedScore` state, before the `return`), then this JSX will compile.

```tsx
                    {fixture.state === 'scheduled' ? (
                        <div className="mt-6 border border-dashed border-hq-border-strong px-6 py-9 text-center">
                            <p className="mb-2 text-3xl">⚽</p>
                            <p className="font-display text-lg text-hq-paper uppercase">
                                Todavía no hay datos de jugadores
                            </p>
                            <p className="mt-1.5 font-mono text-[11px] text-hq-moss-dim">
                                Cuando empiece el partido aparecerán aquí los
                                puntos de cada jugador
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="mt-6">
                                <HqMatchPitch lineups={lineups} onSelect={handleSelectLineupEntry} />
                            </div>

                            <div className="mt-4 flex flex-wrap gap-2 border border-hq-border bg-hq-panel px-4 py-3 font-mono text-[11px] text-hq-moss">
                                <span className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1">
                                    ⚽ Gol
                                </span>
                                <span className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1">
                                    <span className="border border-hq-live px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-live">PP</span>
                                    Autogol
                                </span>
                                <span className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1">
                                    <span className="inline-block h-[18px] w-3 rounded-[1px] bg-hq-gold" />
                                    Amarilla
                                </span>
                                <span className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1">
                                    <span className="inline-block h-[18px] w-3 rounded-[1px] bg-hq-live" />
                                    Roja
                                </span>
                                <span className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1">
                                    <span className="border border-hq-live px-1.5 py-0.5 font-mono text-[10px] font-bold text-hq-live">P✗</span>
                                    Penalti fallado
                                </span>
                                <span className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1">
                                    <span className="border border-hq-lime bg-hq-ink px-1 py-px font-mono text-[9px] font-bold text-hq-lime">↳54</span>
                                    Entra (min.)
                                </span>
                                <span className="inline-flex items-center gap-1.5 border border-hq-border bg-hq-panel-alt px-2 py-1">
                                    <img src="/images/dazn-logo.png" alt="DAZN" className="h-3.5 w-3.5" />
                                    Puntos DAZN
                                </span>
                            </div>

                            <div className="mt-5 flex gap-0.5 border-b border-hq-border-strong">
                                {(
                                    [
                                        ['bench', 'Suplentes'],
                                        ['stats', 'Datos del partido'],
                                        ['timeline', 'Cronología'],
                                    ] as const
                                ).map(([tab, label]) => (
                                    <button
                                        key={tab}
                                        type="button"
                                        onClick={() => setActiveTab(tab)}
                                        className={cn(
                                            '-mb-px border-b-2 px-4 py-2.5 font-mono text-[11px] font-bold tracking-wider uppercase',
                                            activeTab === tab
                                                ? 'border-hq-lime text-hq-lime'
                                                : 'border-transparent text-hq-moss hover:text-hq-paper',
                                        )}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>

                            <div className="mt-4">
                                {activeTab === 'bench' && (
                                    <HqFixtureBench
                                        lineups={lineups}
                                        localTeamId={fixture.local_team.id}
                                        guestTeamId={fixture.guest_team.id}
                                        onSelect={handleSelectLineupEntry}
                                    />
                                )}
                                {activeTab === 'stats' && <HqFixtureTeamStats stats={team_stats} />}
                                {activeTab === 'timeline' && (
                                    <HqFixtureTimeline events={events} localTeamId={fixture.local_team.id} />
                                )}
                            </div>
                        </>
                    )}
```

Destructure `lineups`, `events`, `team_stats` from the component's props alongside the existing `fixture`, `weekFixtures`, `scores` at the top of `FixtureShow`.

- [ ] **Step 4: Wire the modal trigger through `onSelect`**

`isLive`, `hasScore`, `selectedScore`/`setSelectedScore`, and the `<HqPlayerStatsModal ...>` render at the bottom **stay** as-is — only `localScores`/`guestScores`/`minPlayedRows` are now dead (they existed only to feed the deleted `TeamColumn`s) and should be removed along with their declarations.

`HqLineupPlayerToken`/`HqMatchPitch`/`HqFixtureBench` (Tasks 5-7) already accept an `onSelect?: (entry: FixtureLineupEntry) => void` prop. `HqPlayerStatsModal` expects an `HqPlayerStatsEntry` built from a `PlayerScore`, not a `FixtureLineupEntry` — the two are different shapes, so resolve the matching `PlayerScore` by `player.id` before calling `setSelectedScore`:

```tsx
    const scoresByPlayerId = useMemo(
        () => new Map(scores.map((score) => [score.player.id, score])),
        [scores],
    );

    const handleSelectLineupEntry = (entry: FixtureLineupEntry) => {
        const score = entry.player ? scoresByPlayerId.get(entry.player.id) : undefined;

        if (score) {
            setSelectedScore(score);
        }
    };
```

Add `useMemo` to the existing `import { useState } from 'react';` line (`import { useMemo, useState } from 'react';`). Pass `onSelect={handleSelectLineupEntry}` to both `<HqMatchPitch lineups={lineups} onSelect={handleSelectLineupEntry} />` and `<HqFixtureBench ... onSelect={handleSelectLineupEntry} />` in the Step 3 JSX above.

A player with no `PlayerScore` this week (e.g. didn't feature in Fantasy stats despite appearing in the real lineup) silently does nothing on click — acceptable for this phase, matches the spec's scope (the modal stays 100% Fantasy-sourced, so a player Fantasy has no row for has no modal to show).

- [ ] **Step 5: Run the frontend build**

Run: `npm run build`
Expected: no type errors, no unused-import lint failures (`TeamColumn`/`PlayerRow` and any now-unused imports like `didNotPlayMatch`, `MatchEventIcons`, `matchPointsBadgeClass` if no longer referenced directly in this file — remove any import that the deletion in Step 1 leaves dangling; keep the ones `HqPlayerStatsModal`'s trigger logic still needs).

- [ ] **Step 6: Manual browser check**

Use the `run` skill to start the app and open a fixture with real synced lineup/event data (or seed one manually via `season:sync-live-match-data` against a temporarily-shifted fixture date, same technique used earlier this session). Confirm: pitch renders both teams' starters at sensible positions, bench tab lists non-starters with position/sub badges, stats tab shows bar comparisons, timeline tab lists events in order, clicking a pitch/bench player with a `PlayerScore` opens the modal.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/fixtures/show.tsx resources/js/components/hq-match-pitch.tsx resources/js/components/hq-fixture-bench.tsx resources/js/components/hq-lineup-player-token.tsx
git commit -m "feat: wire tactical pitch and tabs into the fitxa de partit, remove the old player list"
```

---

### Task 9: `HqPlayerStatsModal` header — position badge + worldcup26 sub-minute badge

**Files:**
- Modify: `resources/js/components/hq-player-stats-modal.tsx`
- Modify: `resources/js/pages/fixtures/show.tsx` (pass the extra header data)

**Interfaces:**
- Consumes: `HqPositionTag` (existing), `FixtureLineupEntry` (Task 4, for the real worldcup26 `position` text and `sub_minute` — looked up by `player.id` from the page's `lineups` prop).
- Produces: `HqPlayerStatsEntry` gains two new optional fields: `matchPosition?: string` (worldcup26 real position text) and `subMinute?: { minute: number; direction: 'in' | 'out' } | null`.

- [ ] **Step 1: Extend the modal's props and header**

In `resources/js/components/hq-player-stats-modal.tsx`:

- Extend `HqPlayerStatsEntry`:

```ts
export interface HqPlayerStatsEntry {
    player: Player;
    team: Team;
    points: number;
    daznPoints?: number;
    stats: JornadaStats;
    lineupManager?: SeasonManager | null;
    matchPosition?: string;
    subMinute?: { minute: number; direction: 'in' | 'out' } | null;
}
```

- In the header JSX, right after the existing `<HqPositionTag position={player.position} />` line inside the `<div className="mt-1 flex items-center gap-2">` block, add the real match position when present:

```tsx
                    <div className="mt-1 flex items-center gap-2">
                        {matchPosition && (
                            <span className="font-mono text-[11px] text-hq-paper">{matchPosition}</span>
                        )}
                        <HqPositionTag position={player.position} />
```

Destructure `matchPosition`, `subMinute` from `entry` alongside the existing `player, team, points, daznPoints, stats, lineupManager` destructure.

- Add the sub-minute badge next to the avatar (wrap the existing `<EntityImage .../>` in a `relative` container):

```tsx
                    <div className="relative">
                        <EntityImage
                            src={player.image}
                            alt={player.nickname}
                            fallback={User}
                            className="h-16 w-16 border-2 border-hq-border-strong bg-hq-border"
                        />
                        {subMinute && (
                            <span
                                className={cn(
                                    'absolute -right-2 -bottom-1 whitespace-nowrap border bg-hq-ink px-1.5 py-0.5 font-mono text-[10px] font-bold',
                                    subMinute.direction === 'out' ? 'border-hq-live text-hq-live' : 'border-hq-lime text-hq-lime',
                                )}
                            >
                                ↳{subMinute.minute}'
                            </span>
                        )}
                    </div>
```

Remove the old standalone `<EntityImage .../>` line that this replaces.

- [ ] **Step 2: Pass the new fields from `fixtures/show.tsx`**

In `handleSelectLineupEntry` (Task 8, Step 4), pass the extra fields through when calling `setSelectedScore` — but `setSelectedScore` takes a `PlayerScore`, not the final `HqPlayerStatsEntry` shape (that mapping happens later, at the `<HqPlayerStatsModal entry={...} />` call site, same as today). Update that call site instead:

```tsx
            <HqPlayerStatsModal
                entry={
                    selectedScore
                        ? {
                              player: selectedScore.player,
                              team: selectedScore.team,
                              points: selectedScore.points,
                              daznPoints:
                                  fixture.state === 'finished'
                                      ? selectedScore.stats.marca_points?.[1]
                                      : undefined,
                              stats: selectedScore.stats,
                              lineupManager: selectedScore.lineup_manager,
                              matchPosition: lineups.find((entry) => entry.player?.id === selectedScore.player.id)?.position,
                              subMinute: (() => {
                                  const entry = lineups.find((e) => e.player?.id === selectedScore.player.id);

                                  if (!entry || entry.sub_minute === null) {
                                      return null;
                                  }

                                  return { minute: entry.sub_minute, direction: entry.subbed_out ? 'out' : 'in' as const };
                              })(),
                          }
                        : null
                }
                onClose={() => setSelectedScore(null)}
            />
```

- [ ] **Step 3: Run the frontend build**

Run: `npm run build`
Expected: no type errors.

- [ ] **Step 4: Manual browser check**

Open the modal for a real synced player who was substituted — confirm the sub-minute badge shows on the photo and the real worldcup26 position text shows next to the Fantasy position badge.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/hq-player-stats-modal.tsx resources/js/pages/fixtures/show.tsx
git commit -m "feat: show real match position and sub-minute badge in the player modal"
```

---

## After all tasks

Run `php artisan test` and `npm run build` once more end-to-end. Do a final manual pass in the browser (per the `run` skill) against the same Alavés–Villarreal fixture used throughout the design session, confirming the whole page — header, pitch, legend, three tabs, modal — matches the approved mockups.
