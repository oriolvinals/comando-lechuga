# Live Match Data Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sync live/recently-finished fixture state, score, formation, lineups (starters/subs/positions/stats) and events (goals/cards) from worldcup26.ir into the database, replacing `SyncCurrentSeasonFixtures` as the writer of `state`/`local_score`/`guest_score`.

**Architecture:** A new scheduled command, `season:sync-live-match-data` (class `SyncLiveSeasonMatchData`), selects fixtures in a 4-hour date window around `now()`, fetches each one's full event payload from `Worldcup26Connector::getEvent()` (already built in phase 2), and upserts `Fixture` fields plus two new tables, `fixture_lineups` (one row per player per fixture) and `fixture_events` (one row per goal/card, fully replaced each sync). `SyncCurrentSeasonFixtures` stops writing `state`/`local_score`/`guest_score` in the same phase, since the new command now owns that data.

**Tech Stack:** Laravel 12, Pest, Saloon (already wired via `Worldcup26Connector`), MySQL/SQLite JSON columns.

**Spec:** `docs/superpowers/specs/2026-08-29-live-match-data-sync-design.md`

## Global Constraints

- `fixture_lineups.team_id` is the team the player played for **in that fixture**, never `Player.team_id` (their current club) — same invariant as `PlayerScore.team_id`.
- The `getEvent()` payload shape used throughout this plan was verified live against a real match (`GET /get/soccer/esp.1/events/401882926`, Getafe–Alavés, 2026-08-29) — use the exact paths given in each task, not the approximate shape from earlier brainstorming notes.
- Fixture event `type` is derived from the API's boolean flags (`scoringPlay`/`yellowCard`/`redCard`/`penaltyKick`), never by parsing `type.text` — free text varies (`"Goal"`, `"Goal - Header"`, ...) and isn't a reliable switch key.
- `fixture_lineups` is upserted (`updateOrCreate` on `(fixture_id, player_id)`); `fixture_events` is deleted and fully recreated per fixture on every sync — the API always returns the complete list, there's no "since X" delta endpoint.
- All writes for one fixture (fixture row + its lineups + its events) happen inside a single `DB::transaction()`.
- A per-fixture network/JSON failure is caught, logged, and skipped — it must never abort the rest of the fixtures in the run.
- `SyncCurrentSeasonFixtures` keeps writing `fantasy_id`/`date`/`team_local_id`/`team_guest_id` unchanged — only `state`/`local_score`/`guest_score` move to the new command.

---

### Task 1: Migration — `fixture_lineups`, `fixture_events`, and `fixtures` formation columns

**Files:**
- Create: `database/migrations/2026_08_29_150000_create_fixture_lineups_and_events_tables.php`
- Test: none (this codebase has no dedicated migration tests — covered indirectly by the model tests in Tasks 2-4)

**Interfaces:**
- Produces: tables `fixture_lineups` (`fixture_id`, `player_id`, `team_id`, `starter`, `position`, `jersey`, `subbed_in`, `subbed_out`, `counterpart_player_id`, `sub_minute`, `stats`, unique on `(fixture_id, player_id)`) and `fixture_events` (`fixture_id`, `team_id`, `player_id` nullable, `type`, `minute`, `is_own_goal`, `is_penalty`); columns `fixtures.local_formation`, `fixtures.guest_formation` (string, nullable).

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
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->string('local_formation')->nullable()->after('state');
            $table->string('guest_formation')->nullable()->after('local_formation');
        });

        Schema::create('fixture_lineups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->boolean('starter')->nullable(false)->default(false);
            $table->string('position')->nullable(false)->default('');
            $table->string('jersey')->nullable(false)->default('');
            $table->boolean('subbed_in')->nullable(false)->default(false);
            $table->boolean('subbed_out')->nullable(false)->default(false);
            $table->foreignId('counterpart_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->unsignedTinyInteger('sub_minute')->nullable();
            $table->json('stats')->nullable(false);

            $table->unique(['fixture_id', 'player_id']);
        });

        Schema::create('fixture_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('type')->nullable(false);
            $table->unsignedTinyInteger('minute')->nullable(false);
            $table->boolean('is_own_goal')->nullable(false)->default(false);
            $table->boolean('is_penalty')->nullable(false)->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_events');
        Schema::dropIfExists('fixture_lineups');

        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn(['local_formation', 'guest_formation']);
        });
    }
};
```

- [ ] **Step 2: Run migrations to verify they apply cleanly**

Run: `php artisan migrate`
Expected: the three new/altered tables appear with no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_08_29_150000_create_fixture_lineups_and_events_tables.php
git commit -m "feat: add fixture_lineups, fixture_events tables and fixture formation columns"
```

---

### Task 2: `FixtureLineup` model and factory

**Files:**
- Create: `app/Models/FixtureLineup.php`
- Create: `database/factories/FixtureLineupFactory.php`
- Test: `tests/Feature/Models/FixtureLineupTest.php`

**Interfaces:**
- Consumes: `fixture_lineups` table from Task 1; `App\Models\Fixture`, `App\Models\Player`, `App\Models\Team`.
- Produces: `App\Models\FixtureLineup` with fillable `['fixture_id', 'player_id', 'team_id', 'starter', 'position', 'jersey', 'subbed_in', 'subbed_out', 'counterpart_player_id', 'sub_minute', 'stats']`, relations `fixture()`, `player()`, `team()`, `counterpartPlayer()`, and casts including `'stats' => 'array'`. `FixtureLineupFactory` with sensible defaults (`starter: true`, no sub fields, empty `stats`).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Team;

test('casts its stats to an array and its booleans', function (): void {
    $lineup = FixtureLineup::factory()->create([
        'fixture_id' => Fixture::factory(),
        'player_id' => Player::factory(),
        'team_id' => Team::factory(),
        'starter' => false,
        'subbed_in' => true,
        'stats' => [['name' => 'saves', 'value' => 2]],
    ]);

    expect($lineup->starter)->toBeFalse()
        ->and($lineup->subbed_in)->toBeTrue()
        ->and($lineup->stats)->toBe([['name' => 'saves', 'value' => 2]]);
});

test('belongs to a fixture, a player, a team, and optionally a counterpart player', function (): void {
    $counterpart = Player::factory()->create();
    $lineup = FixtureLineup::factory()->create([
        'fixture_id' => Fixture::factory(),
        'player_id' => Player::factory(),
        'team_id' => Team::factory(),
        'counterpart_player_id' => $counterpart->id,
    ]);

    expect($lineup->fixture)->toBeInstanceOf(Fixture::class)
        ->and($lineup->player)->toBeInstanceOf(Player::class)
        ->and($lineup->team)->toBeInstanceOf(Team::class)
        ->and($lineup->counterpartPlayer->id)->toBe($counterpart->id);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FixtureLineupTest`
Expected: FAIL — class `App\Models\FixtureLineup` not found.

- [ ] **Step 3: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FixtureLineupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fixture_id
 * @property-read int $player_id
 * @property-read int $team_id
 * @property-read bool $starter
 * @property-read string $position
 * @property-read string $jersey
 * @property-read bool $subbed_in
 * @property-read bool $subbed_out
 * @property-read int|null $counterpart_player_id
 * @property-read int|null $sub_minute
 * @property-read array<int, array<string, mixed>> $stats
 */
#[UseFactory(FixtureLineupFactory::class)]
#[Table(name: 'fixture_lineups', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fixture_id', 'player_id', 'team_id', 'starter', 'position', 'jersey', 'subbed_in', 'subbed_out', 'counterpart_player_id', 'sub_minute', 'stats'])]
class FixtureLineup extends Model
{
    /** @use HasFactory<FixtureLineupFactory> */
    use HasFactory;

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function counterpartPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'counterpart_player_id');
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'starter' => false,
        'position' => '',
        'jersey' => '',
        'subbed_in' => false,
        'subbed_out' => false,
        'stats' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fixture_id' => 'int',
            'player_id' => 'int',
            'team_id' => 'int',
            'starter' => 'bool',
            'position' => 'string',
            'jersey' => 'string',
            'subbed_in' => 'bool',
            'subbed_out' => 'bool',
            'counterpart_player_id' => 'int',
            'sub_minute' => 'int',
            'stats' => 'array',
        ];
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureLineup>
 */
class FixtureLineupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => Fixture::factory(),
            'player_id' => Player::factory(),
            'team_id' => Team::factory(),
            'starter' => true,
            'position' => 'Goalkeeper',
            'jersey' => (string) $this->faker->numberBetween(1, 25),
            'subbed_in' => false,
            'subbed_out' => false,
            'counterpart_player_id' => null,
            'sub_minute' => null,
            'stats' => [],
        ];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=FixtureLineupTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/FixtureLineup.php database/factories/FixtureLineupFactory.php tests/Feature/Models/FixtureLineupTest.php
git commit -m "feat: add FixtureLineup model and factory"
```

---

### Task 3: `FixtureEvent` model and factory

**Files:**
- Create: `app/Models/FixtureEvent.php`
- Create: `database/factories/FixtureEventFactory.php`
- Test: `tests/Feature/Models/FixtureEventTest.php`

**Interfaces:**
- Consumes: `fixture_events` table from Task 1; `App\Models\Fixture`, `App\Models\Player`, `App\Models\Team`.
- Produces: `App\Models\FixtureEvent` with fillable `['fixture_id', 'team_id', 'player_id', 'type', 'minute', 'is_own_goal', 'is_penalty']`, relations `fixture()`, `team()`, `player()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\Player;
use App\Models\Team;

test('casts its minute and booleans, and allows a null player', function (): void {
    $event = FixtureEvent::factory()->create([
        'fixture_id' => Fixture::factory(),
        'team_id' => Team::factory(),
        'player_id' => null,
        'type' => 'yellow_card',
        'minute' => 45,
        'is_own_goal' => false,
        'is_penalty' => false,
    ]);

    expect($event->minute)->toBe(45)
        ->and($event->type)->toBe('yellow_card')
        ->and($event->player_id)->toBeNull();
});

test('belongs to a fixture, a team, and optionally a player', function (): void {
    $player = Player::factory()->create();
    $event = FixtureEvent::factory()->create([
        'fixture_id' => Fixture::factory(),
        'team_id' => Team::factory(),
        'player_id' => $player->id,
    ]);

    expect($event->fixture)->toBeInstanceOf(Fixture::class)
        ->and($event->team)->toBeInstanceOf(Team::class)
        ->and($event->player->id)->toBe($player->id);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FixtureEventTest`
Expected: FAIL — class `App\Models\FixtureEvent` not found.

- [ ] **Step 3: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FixtureEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fixture_id
 * @property-read int $team_id
 * @property-read int|null $player_id
 * @property-read string $type
 * @property-read int $minute
 * @property-read bool $is_own_goal
 * @property-read bool $is_penalty
 */
#[UseFactory(FixtureEventFactory::class)]
#[Table(name: 'fixture_events', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fixture_id', 'team_id', 'player_id', 'type', 'minute', 'is_own_goal', 'is_penalty'])]
class FixtureEvent extends Model
{
    /** @use HasFactory<FixtureEventFactory> */
    use HasFactory;

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => '',
        'minute' => 0,
        'is_own_goal' => false,
        'is_penalty' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fixture_id' => 'int',
            'team_id' => 'int',
            'player_id' => 'int',
            'type' => 'string',
            'minute' => 'int',
            'is_own_goal' => 'bool',
            'is_penalty' => 'bool',
        ];
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureEvent>
 */
class FixtureEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => Fixture::factory(),
            'team_id' => Team::factory(),
            'player_id' => Player::factory(),
            'type' => 'goal',
            'minute' => $this->faker->numberBetween(1, 90),
            'is_own_goal' => false,
            'is_penalty' => false,
        ];
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=FixtureEventTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/FixtureEvent.php database/factories/FixtureEventFactory.php tests/Feature/Models/FixtureEventTest.php
git commit -m "feat: add FixtureEvent model and factory"
```

---

### Task 4: `Fixture` model — formation columns and new relations

**Files:**
- Modify: `app/Models/Fixture.php`
- Test: `tests/Feature/Models/FixtureTest.php`

**Interfaces:**
- Consumes: `FixtureLineup` (Task 2), `FixtureEvent` (Task 3).
- Produces: `Fixture::$local_formation`, `Fixture::$guest_formation` (string|null, fillable+cast), `Fixture::fixtureLineups(): HasMany<FixtureLineup>`, `Fixture::fixtureEvents(): HasMany<FixtureEvent>`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Models/FixtureTest.php`:

```php
test('casts and fills its formation columns', function (): void {
    $fixture = Fixture::factory()->create([
        'season_id' => Season::factory(),
        'team_local_id' => Team::factory(),
        'team_guest_id' => Team::factory(),
        'local_formation' => '4-3-3',
        'guest_formation' => '3-5-2',
    ]);

    expect($fixture->local_formation)->toBe('4-3-3')
        ->and($fixture->guest_formation)->toBe('3-5-2');
});

test('has many fixture lineups and fixture events', function (): void {
    $fixture = Fixture::factory()->create([
        'season_id' => Season::factory(),
        'team_local_id' => Team::factory(),
        'team_guest_id' => Team::factory(),
    ]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => Player::factory(), 'team_id' => Team::factory()]);
    FixtureEvent::factory()->create(['fixture_id' => $fixture->id, 'team_id' => Team::factory()]);

    expect($fixture->fixtureLineups)->toHaveCount(1)
        ->and($fixture->fixtureEvents)->toHaveCount(1);
});
```

Add the corresponding `use` statements at the top of the test file: `App\Models\FixtureEvent`, `App\Models\FixtureLineup`, `App\Models\Player`.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FixtureTest`
Expected: FAIL — `local_formation` not fillable / `fixtureLineups`/`fixtureEvents` methods don't exist.

- [ ] **Step 3: Update the model**

In `app/Models/Fixture.php`:

- Add to the docblock: `@property-read string|null $local_formation` and `@property-read string|null $guest_formation`.
- Add `'local_formation'` and `'guest_formation'` to the `#[Fillable(...)]` list.
- Add two relations, alongside the existing `playerScores()`:

```php
    /** @return HasMany<FixtureLineup, $this> */
    public function fixtureLineups(): HasMany
    {
        return $this->hasMany(FixtureLineup::class);
    }

    /** @return HasMany<FixtureEvent, $this> */
    public function fixtureEvents(): HasMany
    {
        return $this->hasMany(FixtureEvent::class);
    }
```

- Add `'local_formation' => 'string', 'guest_formation' => 'string',` to `casts()`.
- No new `use` imports needed in the model itself — `FixtureLineup` and `FixtureEvent` live in the same `App\Models` namespace as `Fixture`, so `FixtureLineup::class` / `FixtureEvent::class` resolve directly.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=FixtureTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Models/Fixture.php tests/Feature/Models/FixtureTest.php
git commit -m "feat: add formation columns and lineup/event relations to Fixture"
```

---

### Task 5: `FixtureState::fromWorldcup26Name()`

**Files:**
- Modify: `app/Enums/FixtureState.php`
- Test: `tests/Feature/Models/FixtureTest.php`

**Interfaces:**
- Produces: `FixtureState::fromWorldcup26Name(string $name): self`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Models/FixtureTest.php`:

```php
test('maps worldcup26 status names to fixture states', function (string $name, FixtureState $state): void {
    expect(FixtureState::fromWorldcup26Name($name))->toBe($state);
})->with([
    ['STATUS_SCHEDULED', FixtureState::Scheduled],
    ['STATUS_FIRST_HALF', FixtureState::FirstHalf],
    ['STATUS_HALFTIME', FixtureState::HalfTime],
    ['STATUS_SECOND_HALF', FixtureState::SecondHalf],
    ['STATUS_FULL_TIME', FixtureState::Finished],
    ['STATUS_POSTPONED', FixtureState::Scheduled],
]);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FixtureTest`
Expected: FAIL — method `fromWorldcup26Name` doesn't exist.

- [ ] **Step 3: Add the method**

In `app/Enums/FixtureState.php`, alongside `fromFantasyId()`:

```php
    public static function fromWorldcup26Name(string $name): self
    {
        return match ($name) {
            'STATUS_FIRST_HALF' => self::FirstHalf,
            'STATUS_HALFTIME' => self::HalfTime,
            'STATUS_SECOND_HALF' => self::SecondHalf,
            'STATUS_FULL_TIME' => self::Finished,
            default => self::Scheduled,
        };
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=FixtureTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Enums/FixtureState.php tests/Feature/Models/FixtureTest.php
git commit -m "feat: map worldcup26 status names to FixtureState"
```

---

### Task 6: `SyncLiveSeasonMatchData` command — fixture window, state/score/formation, lineup upsert

**Files:**
- Create: `app/Console/Commands/SyncLiveSeasonMatchData.php`
- Test: `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`

**Interfaces:**
- Consumes: `Worldcup26Connector::getEvent(int $matchDataId): Response` (existing, phase 2); `FixtureState::fromWorldcup26Name()` (Task 5); `FixtureLineup` (Task 2); `Fixture::$local_formation`/`$guest_formation` (Task 4).
- Produces: command signature `season:sync-live-match-data`, class `App\Console\Commands\SyncLiveSeasonMatchData`. This task covers fixture selection + `state`/`local_score`/`guest_score`/`local_formation`/`guest_formation` update + `fixture_lineups` upsert. Event sync is Task 7, added to the same file.

**worldcup26 `getEvent` response shape used below** (verified live, see plan header):
- `header.competitions[0].status.type.name` — status string (e.g. `STATUS_FULL_TIME`).
- `header.competitions[0].competitors[]` — each has `homeAway` (`"home"`/`"away"`) and `score` (numeric string).
- `rosters[]` — each has `homeAway`, `team.id` (worldcup26 team id), `formation` (string), `roster[]`.
- `rosters[].roster[]` — each has `athlete.id`, `athlete.displayName`, `starter` (bool), `position.displayName`, `jersey` (string), `subbedIn`/`subbedOut` (bool), `subbedInFor.athlete.id`/`subbedOutFor.athlete.id` (present only when relevant), `stats[]` (list of `{name, value, ...}`), `plays[]` (list of `{clock: {displayValue}, substitution: bool, ...}`).

- [ ] **Step 1: Write the failing test — updates fixture state/score/formation**

```php
<?php

use App\Console\Commands\SyncLiveSeasonMatchData;
use App\Enums\FixtureState;
use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function liveMatchEventPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'header' => [
            'competitions' => [
                [
                    'status' => ['type' => ['name' => 'STATUS_FULL_TIME']],
                    'competitors' => [
                        ['homeAway' => 'home', 'score' => '2'],
                        ['homeAway' => 'away', 'score' => '1'],
                    ],
                ],
            ],
        ],
        'rosters' => [],
        'keyEvents' => [],
    ], $overrides);
}

test('updates the fixture state, score and formation from the live event', function (): void {
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

    $payload = liveMatchEventPayload([
        'rosters' => [
            ['homeAway' => 'home', 'team' => ['id' => 83], 'formation' => '4-3-3', 'roster' => []],
            ['homeAway' => 'away', 'team' => ['id' => 86], 'formation' => '3-5-2', 'roster' => []],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutput('1 fixtures synced.')
        ->assertSuccessful();

    $fixture->refresh();
    expect($fixture->state)->toBe(FixtureState::Finished)
        ->and($fixture->local_score)->toBe(2)
        ->and($fixture->guest_score)->toBe(1)
        ->and($fixture->local_formation)->toBe('4-3-3')
        ->and($fixture->guest_formation)->toBe('3-5-2');
});

test('ignores fixtures outside the live window or without a match_data_id', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);

    $tooOld = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 111,
        'date' => now()->subHours(5),
    ]);
    $unlinked = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => null,
        'date' => now()->subMinutes(10),
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make(liveMatchEventPayload()),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutput('0 fixtures synced.')
        ->assertSuccessful();

    expect($tooOld->refresh()->state)->toBe(FixtureState::Scheduled)
        ->and($unlinked->refresh()->state)->toBe(FixtureState::Scheduled);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: FAIL — class `App\Console\Commands\SyncLiveSeasonMatchData` not found.

- [ ] **Step 3: Write the command (fixture selection + state/score/formation)**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FixtureState;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-live-match-data')]
#[Description('Synchronize live/recently-finished fixtures\' state, lineups and events from worldcup26.ir')]
class SyncLiveSeasonMatchData extends Command
{
    private const int LIVE_WINDOW_HOURS = 4;

    /**
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $connector): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->where('date', '<=', now())
            ->where('date', '>=', now()->subHours(self::LIVE_WINDOW_HOURS))
            ->get();

        $synced = 0;
        $unresolved = [];

        foreach ($fixtures as $fixture) {
            try {
                $event = $connector->getEvent($fixture->match_data_id)->throw()->json();
            } catch (FatalRequestException|RequestException|JsonException $exception) {
                Log::warning("Failed to sync live match data for fixture {$fixture->id}: {$exception->getMessage()}");
                $this->warn("Skipped fixture #{$fixture->id}: {$exception->getMessage()}");

                continue;
            }

            DB::transaction(function () use ($fixture, $event, &$unresolved): void {
                $this->syncFixture($fixture, $event);
                $unresolved = [...$unresolved, ...$this->syncLineups($fixture, $event)];
            });

            $synced++;
        }

        $this->info("{$synced} fixtures synced.");

        if ($unresolved !== []) {
            $this->warn('Unresolved players — needs manual review: '.implode(', ', $unresolved));
        }

        return self::SUCCESS;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function syncFixture(Fixture $fixture, array $event): void
    {
        $competition = $event['header']['competitions'][0] ?? [];
        $statusName = (string) ($competition['status']['type']['name'] ?? '');
        $competitors = is_array($competition['competitors'] ?? null) ? $competition['competitors'] : [];
        $rosters = is_array($event['rosters'] ?? null) ? $event['rosters'] : [];

        $fixture->update([
            'state' => FixtureState::fromWorldcup26Name($statusName),
            'local_score' => $this->scoreFor($competitors, 'home'),
            'guest_score' => $this->scoreFor($competitors, 'away'),
            'local_formation' => $this->formationFor($rosters, 'home'),
            'guest_formation' => $this->formationFor($rosters, 'away'),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $competitors
     */
    private function scoreFor(array $competitors, string $homeAway): ?int
    {
        foreach ($competitors as $competitor) {
            if (($competitor['homeAway'] ?? null) === $homeAway) {
                return isset($competitor['score']) ? (int) $competitor['score'] : null;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rosters
     */
    private function formationFor(array $rosters, string $homeAway): ?string
    {
        foreach ($rosters as $roster) {
            if (($roster['homeAway'] ?? null) === $homeAway) {
                return isset($roster['formation']) ? (string) $roster['formation'] : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $event
     * @return list<string>
     */
    private function syncLineups(Fixture $fixture, array $event): array
    {
        $rosters = is_array($event['rosters'] ?? null) ? $event['rosters'] : [];
        $unresolved = [];

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
                    ->where('team_id', $team->id)
                    ->where('match_data_id', $athleteMatchDataId)
                    ->first();

                if ($player === null) {
                    $unresolved[] = (string) ($rosterPlayer['athlete']['displayName'] ?? $athleteMatchDataId);

                    continue;
                }

                $this->upsertLineup($fixture, $team, $player, $rosterPlayer);
            }
        }

        return $unresolved;
    }

    /**
     * @param array<string, mixed> $rosterPlayer
     */
    private function upsertLineup(Fixture $fixture, Team $team, Player $player, array $rosterPlayer): void
    {
        $subbedIn = (bool) ($rosterPlayer['subbedIn'] ?? false);
        $subbedOut = (bool) ($rosterPlayer['subbedOut'] ?? false);

        $counterpartMatchDataId = match (true) {
            $subbedIn => $rosterPlayer['subbedInFor']['athlete']['id'] ?? null,
            $subbedOut => $rosterPlayer['subbedOutFor']['athlete']['id'] ?? null,
            default => null,
        };

        $counterpartPlayer = $counterpartMatchDataId !== null
            ? Player::query()->where('match_data_id', (int) $counterpartMatchDataId)->first()
            : null;

        FixtureLineup::query()->updateOrCreate(
            ['fixture_id' => $fixture->id, 'player_id' => $player->id],
            [
                'team_id' => $team->id,
                'starter' => (bool) ($rosterPlayer['starter'] ?? false),
                'position' => (string) ($rosterPlayer['position']['displayName'] ?? ''),
                'jersey' => (string) ($rosterPlayer['jersey'] ?? ''),
                'subbed_in' => $subbedIn,
                'subbed_out' => $subbedOut,
                'counterpart_player_id' => $counterpartPlayer?->id,
                'sub_minute' => $this->subMinute($rosterPlayer),
                'stats' => is_array($rosterPlayer['stats'] ?? null) ? $rosterPlayer['stats'] : [],
            ],
        );
    }

    /**
     * @param array<string, mixed> $rosterPlayer
     */
    private function subMinute(array $rosterPlayer): ?int
    {
        $plays = is_array($rosterPlayer['plays'] ?? null) ? $rosterPlayer['plays'] : [];

        foreach ($plays as $play) {
            if (($play['substitution'] ?? false) === true) {
                return $this->minuteFromClock((string) ($play['clock']['displayValue'] ?? ''));
            }
        }

        return null;
    }

    private function minuteFromClock(string $displayValue): ?int
    {
        return preg_match('/^(\d+)/', $displayValue, $matches) === 1 ? (int) $matches[1] : null;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: PASS

- [ ] **Step 5: Write the failing test — upserts lineups, including a substitution**

Append to the same test file:

```php
test('upserts fixture_lineups from the rosters, including substitution minute and counterpart', function (): void {
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
    $starter = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);
    $subOut = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5002]);
    $subIn = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5003]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    [
                        'athlete' => ['id' => 5001, 'displayName' => 'Starter One'],
                        'starter' => true,
                        'position' => ['displayName' => 'Goalkeeper'],
                        'jersey' => '1',
                        'subbedIn' => false,
                        'subbedOut' => false,
                        'stats' => [['name' => 'saves', 'value' => 2]],
                    ],
                    [
                        'athlete' => ['id' => 5002, 'displayName' => 'Sub Out'],
                        'starter' => true,
                        'position' => ['displayName' => 'Center Left Midfielder'],
                        'jersey' => '4',
                        'subbedIn' => false,
                        'subbedOut' => true,
                        'subbedOutFor' => ['athlete' => ['id' => 5003]],
                        'plays' => [['clock' => ['displayValue' => "57'"], 'substitution' => true]],
                        'stats' => [],
                    ],
                    [
                        'athlete' => ['id' => 5003, 'displayName' => 'Sub In'],
                        'starter' => false,
                        'position' => ['displayName' => 'Substitute'],
                        'jersey' => '18',
                        'subbedIn' => true,
                        'subbedOut' => false,
                        'subbedInFor' => ['athlete' => ['id' => 5002]],
                        'plays' => [['clock' => ['displayValue' => "57'"], 'substitution' => true]],
                        'stats' => [],
                    ],
                ],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $starterLineup = FixtureLineup::query()->where('player_id', $starter->id)->sole();
    $subOutLineup = FixtureLineup::query()->where('player_id', $subOut->id)->sole();
    $subInLineup = FixtureLineup::query()->where('player_id', $subIn->id)->sole();

    expect($starterLineup->stats)->toBe([['name' => 'saves', 'value' => 2]])
        ->and($subOutLineup->subbed_out)->toBeTrue()
        ->and($subOutLineup->counterpart_player_id)->toBe($subIn->id)
        ->and($subOutLineup->sub_minute)->toBe(57)
        ->and($subInLineup->subbed_in)->toBeTrue()
        ->and($subInLineup->counterpart_player_id)->toBe($subOut->id)
        ->and($subInLineup->sub_minute)->toBe(57);
});

test('running twice with the same payload does not duplicate lineups, and reports unresolved players', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subMinutes(30),
    ]);
    Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Known'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1'],
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5'],
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
    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    expect(FixtureLineup::query()->count())->toBe(1);
});
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: PASS — the implementation from Step 3 already covers this (no code change expected in this step; if it fails, the most likely gap is the `updateOrCreate` key or the `subbedInFor`/`subbedOutFor` lookup — fix against the code in Step 3, don't add new logic beyond what's already there).

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/SyncLiveSeasonMatchData.php tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php
git commit -m "feat: sync fixture state/score/formation and lineups from worldcup26.ir"
```

---

### Task 7: `SyncLiveSeasonMatchData` — event sync and per-fixture failure resilience

**Files:**
- Modify: `app/Console/Commands/SyncLiveSeasonMatchData.php`
- Modify: `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`

**Interfaces:**
- Consumes: `FixtureEvent` (Task 3).
- Produces: extends `handle()`'s per-fixture transaction to also call `syncEvents()`.

**worldcup26 `keyEvents[]` shape used below** (verified live): each entry has `type.text` (free text, not used for the mapping), `clock.displayValue` (e.g. `"57'"`, `"90'+4'"`), `team.id`, `scoringPlay`/`redCard`/`yellowCard`/`ownGoal`/`penaltyKick` (booleans), and `athletesInvolved[]` (list, sometimes absent) with `athletesInvolved[0].id`.

- [ ] **Step 1: Write the failing test — creates events from keyEvents, with the flag-based type mapping**

Append to the test file:

```php
test('replaces fixture_events from keyEvents on every sync, mapped from the API flags', function (): void {
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
    $scorer = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001]);

    $payload = liveMatchEventPayload([
        'keyEvents' => [
            [
                'type' => ['text' => 'Goal'],
                'clock' => ['displayValue' => "73'"],
                'team' => ['id' => 83],
                'scoringPlay' => true,
                'redCard' => false,
                'yellowCard' => false,
                'ownGoal' => false,
                'penaltyKick' => false,
                'athletesInvolved' => [['id' => 5001]],
            ],
            [
                'type' => ['text' => 'Yellow Card'],
                'clock' => ['displayValue' => "44'"],
                'team' => ['id' => 86],
                'scoringPlay' => false,
                'redCard' => false,
                'yellowCard' => true,
                'ownGoal' => false,
                'penaltyKick' => false,
                // no athletesInvolved — must still create the event, with a null player
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $goal = FixtureEvent::query()->where('type', 'goal')->sole();
    $card = FixtureEvent::query()->where('type', 'yellow_card')->sole();

    expect($goal->minute)->toBe(73)
        ->and($goal->player_id)->toBe($scorer->id)
        ->and($goal->team_id)->toBe($home->id)
        ->and($card->minute)->toBe(44)
        ->and($card->player_id)->toBeNull()
        ->and($card->team_id)->toBe($away->id);

    // Second sync with a different payload replaces, not appends
    $connector2 = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make(liveMatchEventPayload()),
    ]));
    app()->instance(Worldcup26Connector::class, $connector2);
    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    expect(FixtureEvent::query()->where('fixture_id', $fixture->id)->count())->toBe(0);
});
```

Add `use App\Models\FixtureEvent;` to the test file's imports.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: FAIL — `fixture_events` table stays empty, no `syncEvents()` call exists yet.

- [ ] **Step 3: Add event sync to the command**

In `app/Console/Commands/SyncLiveSeasonMatchData.php`:

- Add `use App\Models\FixtureEvent;` to the imports.
- In `handle()`, extend the transaction closure:

```php
            DB::transaction(function () use ($fixture, $event, &$unresolved): void {
                $this->syncFixture($fixture, $event);
                $unresolved = [...$unresolved, ...$this->syncLineups($fixture, $event)];
                $this->syncEvents($fixture, $event);
            });
```

- Add the new private methods:

```php
    /**
     * @param array<string, mixed> $event
     */
    private function syncEvents(Fixture $fixture, array $event): void
    {
        FixtureEvent::query()->where('fixture_id', $fixture->id)->delete();

        $keyEvents = is_array($event['keyEvents'] ?? null) ? $event['keyEvents'] : [];

        foreach ($keyEvents as $keyEvent) {
            $type = $this->eventType($keyEvent);

            if ($type === null) {
                Log::info('Unmapped worldcup26 event type: '.($keyEvent['type']['text'] ?? 'unknown'));

                continue;
            }

            $teamMatchDataId = (int) ($keyEvent['team']['id'] ?? 0);
            $team = Team::query()->where('match_data_id', $teamMatchDataId)->first();

            if ($team === null) {
                continue;
            }

            $athletes = is_array($keyEvent['athletesInvolved'] ?? null) ? $keyEvent['athletesInvolved'] : [];
            $athleteMatchDataId = isset($athletes[0]['id']) ? (int) $athletes[0]['id'] : null;
            $player = $athleteMatchDataId !== null
                ? Player::query()->where('match_data_id', $athleteMatchDataId)->first()
                : null;

            FixtureEvent::query()->create([
                'fixture_id' => $fixture->id,
                'team_id' => $team->id,
                'player_id' => $player?->id,
                'type' => $type,
                'minute' => $this->minuteFromClock((string) ($keyEvent['clock']['displayValue'] ?? '')) ?? 0,
                'is_own_goal' => (bool) ($keyEvent['ownGoal'] ?? false),
                'is_penalty' => (bool) ($keyEvent['penaltyKick'] ?? false),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $keyEvent
     */
    private function eventType(array $keyEvent): ?string
    {
        return match (true) {
            ($keyEvent['scoringPlay'] ?? false) === true => 'goal',
            ($keyEvent['yellowCard'] ?? false) === true => 'yellow_card',
            ($keyEvent['redCard'] ?? false) === true => 'red_card',
            ($keyEvent['penaltyKick'] ?? false) === true => 'penalty_missed',
            default => null,
        };
    }
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: PASS

- [ ] **Step 5: Write the failing test — a network failure on one fixture doesn't block the rest**

Append to the test file:

```php
test('skips a fixture whose getEvent call fails, without blocking the rest', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $failing = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 111,
        'date' => now()->subMinutes(10),
    ]);
    $ok = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 222,
        'date' => now()->subMinutes(20),
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => function ($pendingRequest) {
            if (str_contains($pendingRequest->getRequest()->resolveEndpoint(), '111')) {
                return MockResponse::make([], 500);
            }

            return MockResponse::make(liveMatchEventPayload());
        },
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutput('1 fixtures synced.')
        ->assertSuccessful();

    expect($failing->refresh()->state)->toBe(FixtureState::Scheduled)
        ->and($ok->refresh()->state)->toBe(FixtureState::Finished);
});
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: PASS — the `try`/`catch` in `handle()` from Task 6 Step 3 already covers this; this step only adds test coverage. If it fails, check that `$connector->getEvent(...)->throw()` is actually throwing on the 500 response (Saloon throws on `throw()` for non-2xx by default).

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions in other command/model tests.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/SyncLiveSeasonMatchData.php tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php
git commit -m "feat: sync fixture events and isolate per-fixture sync failures"
```

---

### Task 8: Retire `SyncCurrentSeasonFixtures`' state/score writes, schedule the new command

**Files:**
- Modify: `app/Console/Commands/SyncCurrentSeasonFixtures.php`
- Modify: `tests/Feature/Console/Commands/SyncCurrentSeasonFixturesTest.php`
- Modify: `bootstrap/app.php`

**Interfaces:**
- Consumes: `season:sync-live-match-data` (Tasks 6-7) as the new owner of `state`/`local_score`/`guest_score`.
- Produces: `SyncCurrentSeasonFixtures` no longer touches those three fields; `season:sync-live-match-data` runs `everyMinute()` in the schedule.

- [ ] **Step 1: Update the failing-first test**

Replace the entire existing test in `tests/Feature/Console/Commands/SyncCurrentSeasonFixturesTest.php` (name included) with this version — the fixture now starts with an explicit non-default state/score so a no-op is observable:

```php
test('replaces the active season fixtures from every week, without touching state or score', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $localTeam = Team::factory()->create(['fantasy_id' => 18]);
    $guestTeam = Team::factory()->create(['fantasy_id' => 6]);
    $season->teams()->attach([$localTeam->id, $guestTeam->id]);
    Fixture::factory()->create([
        'fantasy_id' => 11,
        'season_id' => $season->id,
        'team_local_id' => $localTeam->id,
        'team_guest_id' => $guestTeam->id,
        'state' => FixtureState::FirstHalf,
        'local_score' => 5,
        'guest_score' => 5,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetFixturesRequest::class => function ($pendingRequest): MockResponse {
            if ($pendingRequest->getRequest()->query()->get('weekNumber') !== 1) {
                return MockResponse::make([]);
            }

            return MockResponse::make([
                [
                    'id' => '11',
                    'matchDate' => '2026-08-22T19:30:00+02:00',
                    'localId' => 18,
                    'visitorId' => 6,
                    'matchState' => 7,
                    'localScore' => 2,
                    'visitorScore' => 1,
                ],
            ]);
        },
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonFixtures::class)
        ->expectsOutput('1 fixtures synchronized.')
        ->assertSuccessful();

    $fixture = Fixture::query()->sole();

    expect($fixture->fantasy_id)->toBe(11)
        ->and($fixture->date)->toEqual(CarbonImmutable::parse('2026-08-22T19:30:00+02:00')->setTimezone('Europe/Madrid'))
        ->and($fixture->state)->toBe(FixtureState::FirstHalf)
        ->and($fixture->local_score)->toBe(5)
        ->and($fixture->guest_score)->toBe(5);
});
```

No new imports needed — `App\Enums\FixtureState` is already imported in this file.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SyncCurrentSeasonFixturesTest`
Expected: FAIL — the command still overwrites `state`/`local_score`/`guest_score` with the API's values (`Finished`/2/1) instead of leaving the pre-existing `FirstHalf`/5/5.

- [ ] **Step 3: Stop writing those three fields**

In `app/Console/Commands/SyncCurrentSeasonFixtures.php`, remove `local_score`, `guest_score`, and `state` from the `$fixtures[]` array built in the loop, so it becomes:

```php
                $fixtures[] = [
                    'fantasy_id' => (int)$fixtureData['id'],
                    'season_id' => $season->id,
                    'week_number' => $weekNumber,
                    'date' => CarbonImmutable::parse($fixtureData['matchDate'])
                        ->setTimezone((string) config('app.timezone')),
                    'team_local_id' => $localTeam->id,
                    'team_guest_id' => $guestTeam->id,
                ];
```

Since `updateOrCreate()` only sets the keys present in the array on both insert and update, a **new** fixture row will now be created with `state` defaulting to `FixtureState::Scheduled` (the model's `$attributes` default) and `local_score`/`guest_score` left `null` (column default) until `season:sync-live-match-data` links and syncs it — this matches the spec (`SyncCurrentSeasonFixtures` is no longer the source of those fields at all, not even for new rows).

`FixtureState::fromFantasyId(...)` was the only use of `FixtureState` in this command — after removing that line, also remove the now-unused `use App\Enums\FixtureState;` import at the top of the file.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SyncCurrentSeasonFixturesTest`
Expected: PASS

- [ ] **Step 5: Schedule the new command**

In `bootstrap/app.php`, inside `->withSchedule(function (Schedule $schedule): void { ... })`, add, near the other live/`sync-fixtures` entries:

```php
        $schedule->command('season:sync-live-match-data')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 7: Format and statically analyze**

Run: `composer format` (Pint) and `composer analyze` (PHPStan). Fix any reported issues before committing.

- [ ] **Step 8: Commit**

```bash
git add app/Console/Commands/SyncCurrentSeasonFixtures.php tests/Feature/Console/Commands/SyncCurrentSeasonFixturesTest.php bootstrap/app.php
git commit -m "refactor: retire state/score writes from SyncCurrentSeasonFixtures, schedule live match-data sync"
```

---

## After all tasks

Run `php artisan test` once more end-to-end and confirm every fixture-related command/model test passes together (Tasks 1-8 touch overlapping files). This phase is then complete — phase 4 (fitxa de partit redesign to actually display this data) is a separate, later brainstorming session.
