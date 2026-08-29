# worldcup26-as-primary-source data model Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reset the database and rebuild the data model so worldcup26 owns player/match identity and match stats, LaLiga Fantasy is reduced to enrichment (photo/market/status) plus manager lineups, and the `player_scores`/`manager_lineup_players` duplication of `points`/`stats` collapses into `fixture_lineups`.

**Architecture:** A wipe migration clears every table except `seasons`/`season_managers`. `players.fantasy_id` becomes nullable (worldcup26 will create rows once its own sync phase ships; that sync logic is explicitly out of scope here — this plan only changes the schema and the read-side code that already exists). `fixture_lineups` absorbs `player_scores`'s role (`fantasy_points`, `fantasy_stats`, replacing `points`/`stats`). `manager_lineup_players` keeps its own identity (a manager sets a lineup before any match data exists) but drops its own `points`/`stats` and gains `fixture_id`, so it can look up the matching `FixtureLineup` row instead of storing a duplicate copy. Every controller/concern/command that reads or writes the old shape is updated to match; commands that only exist to write `player_scores`/the removed `manager_lineup_players` columns and cannot function against the new schema are deleted outright (their replacement is explicitly a future, separate sync-design phase — nothing here reaches production until that phase also ships, per the user).

**Tech Stack:** Laravel 12 + Pest (backend), Inertia + React 19 + TypeScript + Tailwind v4 (frontend). No JS test runner — frontend correctness is verified by `npm run build` (type-check) and a manual browser check, not automated tests.

**Spec:** `docs/superpowers/specs/2026-08-29-worldcup26-primary-source-design.md`

## Global Constraints

- `fixture_lineups.stats` (worldcup26 raw counters) and `fixture_lineups.fantasy_stats` (Fantasy's point breakdown) are two separate JSON columns on the same row — never conflate them.
- `manager_lineup_players` is never deleted as a table — only its `points`/`stats` columns are dropped, replaced by a `fixture_id` FK used to look up the equivalent `FixtureLineup` row at read time.
- Nothing in this plan designs new sync commands or changes what's scheduled to run — commands that can no longer function against the new schema are deleted, not redesigned. `SyncCurrentSeasonManagerLineups` is the one exception: it keeps writing `ManagerLineup` and now also resolves+writes `ManagerLineupPlayer.fixture_id`, since `manager_lineups`/`manager_lineup_players` both still exist and this is a mechanical consequence of the column change, not new design.
- `player_scores` and its model/factory/relations are deleted entirely, with no replacement column carrying `ideal_formation` anywhere.
- Every existing JSON prop shape the frontend already consumes stays wire-compatible unless a task below explicitly says otherwise (e.g. `lineups.*.points`/`lineups.*.dazn_points` keep their names even though the backend source changes) — this keeps the frontend blast radius to only the two changes actually decided: dropping `ideal_formation`, and dropping the fixture-page Fantasy-only fallback list.
- This repo's factories route season-scoped Fantasy fields (position, market_value, etc.) through `PlayerSeason` already (`PlayerFactory::create()`); don't reintroduce a `position` column on `players`.

---

### Task 1: Wipe migration — clear every table except `seasons` and `season_managers`

**Files:**
- Create: `database/migrations/2026_08_30_100000_wipe_data_for_worldcup26_primary_source.php`
- Test: `tests/Feature/Migrations/WipeDataForWorldcup26PrimarySourceTest.php`

**Interfaces:**
- Produces: after this migration runs, every table except `seasons` and `season_managers` is empty. No schema changes in this task — only data.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;

test('wipes every table except seasons and season_managers', function (): void {
    $season = Season::factory()->create();
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    Team::factory()->create();
    Player::factory()->create();
    Fixture::factory()->create(['season_id' => $season->id]);

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_08_30_100000_wipe_data_for_worldcup26_primary_source.php', '--force' => true]);

    expect(Season::query()->count())->toBe(1)
        ->and(SeasonManager::query()->count())->toBe(1)
        ->and(Team::query()->count())->toBe(0)
        ->and(Player::query()->count())->toBe(0)
        ->and(Fixture::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=WipeDataForWorldcup26PrimarySourceTest`
Expected: FAIL — the migration file doesn't exist yet, so `--path` finds nothing and the tables still have their seeded rows (`Team::query()->count()` etc. stay non-zero).

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES_TO_WIPE = [
        'activities',
        'fixture_events',
        'fixture_lineups',
        'fixtures',
        'manager_lineup_players',
        'manager_lineups',
        'manager_players',
        'market_players',
        'player_markets',
        'player_scores',
        'player_seasons',
        'players',
        'season_team',
        'teams',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES_TO_WIPE as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Data-only migration — deleted rows cannot be restored.
    }
};
```

Note: `activities` (the `Activity` model — ownership history) and `season_team` (the `Season`↔`Team` pivot) aren't in the spec's explicit list but are exactly the kind of data that references wiped tables (`activities.player_id`, `season_team.team_id`) — leaving them would either FK-fail or leave orphaned rows. Check `database/migrations/` for the exact current table names of any model you're unsure about before relying on this list (e.g. confirm the `Season`↔`Team` pivot table name via `php artisan tinker --execute="echo (new App\Models\Season)->teams()->getTable();"` if it's not obviously `season_team`).

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=WipeDataForWorldcup26PrimarySourceTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions (this migration doesn't run automatically in the test suite unless `migrate:fresh` picks it up — verify no other test asserts data exists purely from a prior migration's seed).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_30_100000_wipe_data_for_worldcup26_primary_source.php tests/Feature/Migrations/WipeDataForWorldcup26PrimarySourceTest.php
git commit -m "feat: wipe all data except seasons/season_managers for the worldcup26-primary-source reset"
```

---

### Task 2: `seasons.match_data_season_slug`

**Files:**
- Create: `database/migrations/2026_08_30_100100_add_match_data_season_slug_to_seasons_table.php`
- Modify: `app/Models/Season.php`
- Modify: `database/factories/SeasonFactory.php`
- Test: `tests/Feature/Models/SeasonTest.php` (create if it doesn't exist — check with `Glob` first)

**Interfaces:**
- Produces: `Season.match_data_season_slug: ?string`, nullable, backfilled to `"2026-27-laliga"` for every existing row.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Season;

test('has a nullable match_data_season_slug backfilled for existing seasons', function (): void {
    $season = Season::factory()->create();

    expect($season->match_data_season_slug)->toBe('2026-27-laliga');
});

test('match_data_season_slug can be null for a season created after the backfill', function (): void {
    $season = Season::factory()->create(['match_data_season_slug' => null]);

    expect($season->match_data_season_slug)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SeasonTest`
Expected: FAIL — `match_data_season_slug` isn't a column and isn't in the factory yet.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->string('match_data_season_slug')->nullable()->after('fantasy_id');
        });

        DB::table('seasons')->update(['match_data_season_slug' => '2026-27-laliga']);
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn('match_data_season_slug');
        });
    }
};
```

- [ ] **Step 4: Update the `Season` model**

In `app/Models/Season.php`, add `@property-read string|null $match_data_season_slug` to the docblock (after the `$fantasy_id` line), add `'match_data_season_slug'` to the `#[Fillable(...)]` array (after `'fantasy_id'`), and add `'match_data_season_slug' => 'string'` to `casts()`.

- [ ] **Step 5: Update `SeasonFactory`**

Read `database/factories/SeasonFactory.php` first to match its existing style, then add `'match_data_season_slug' => '2026-27-laliga',` to `definition()`'s returned array.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=SeasonTest`
Expected: PASS

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_30_100100_add_match_data_season_slug_to_seasons_table.php app/Models/Season.php database/factories/SeasonFactory.php tests/Feature/Models/SeasonTest.php
git commit -m "feat: add Season.match_data_season_slug, backfilled to the current worldcup26 season"
```

---

### Task 3: `players.fantasy_id` nullable

**Files:**
- Create: `database/migrations/2026_08_30_100200_make_players_fantasy_id_nullable.php`
- Modify: `app/Models/Player.php`
- Test: `tests/Feature/Models/PlayerTest.php` (check with `Glob` whether it exists; add to it if so, create if not)

**Interfaces:**
- Produces: `Player.fantasy_id: int|null` — a `Player` row can now exist with no Fantasy link.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Player;

test('fantasy_id can be null for a worldcup26-only player', function (): void {
    $player = Player::factory()->create(['fantasy_id' => null]);

    expect($player->fantasy_id)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=PlayerTest`
Expected: FAIL — `fantasy_id` is `NOT NULL` at the DB level, insert throws.

- [ ] **Step 3: Write the migration**

Check `composer.json` for `doctrine/dbal` first (`->change()` requires it) — if missing, run `composer require doctrine/dbal --dev` before writing this migration (same note as the existing `2026_08_30_090000_make_fixture_lineups_player_id_nullable.php` migration).

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
        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedInteger('fantasy_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedInteger('fantasy_id')->nullable(false)->change();
        });
    }
};
```

- [ ] **Step 4: Update the `Player` model**

In `app/Models/Player.php`, change the docblock line `@property-read int $fantasy_id` to `@property-read int|null $fantasy_id`, and in `casts()` change `'fantasy_id' => 'int'` — leave the cast as-is (Laravel's `int` cast already passes `null` through unchanged; only the docblock needs updating).

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --filter=PlayerTest`
Expected: PASS

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_30_100200_make_players_fantasy_id_nullable.php app/Models/Player.php tests/Feature/Models/PlayerTest.php
git commit -m "feat: make players.fantasy_id nullable for worldcup26-only players"
```

---

### Task 4: `fixture_lineups` gains `fantasy_points`/`fantasy_stats`

**Files:**
- Create: `database/migrations/2026_08_30_100300_add_fantasy_points_and_stats_to_fixture_lineups_table.php`
- Modify: `app/Models/FixtureLineup.php`
- Modify: `database/factories/FixtureLineupFactory.php`
- Test: `tests/Feature/Models/FixtureLineupTest.php` (check with `Glob` whether it exists; add to it if so, create if not)

**Interfaces:**
- Produces: `FixtureLineup.fantasy_points: int|null`, `FixtureLineup.fantasy_stats: array<string, mixed>|null`. Coexist with the existing `stats` column (worldcup26 raw counters) without conflict.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\FixtureLineup;

test('stores fantasy points and stats alongside the worldcup26 raw stats', function (): void {
    $lineup = FixtureLineup::factory()->create([
        'stats' => [['name' => 'totalGoals', 'value' => 1]],
        'fantasy_points' => 8,
        'fantasy_stats' => ['marca_points' => [3, 1]],
    ]);

    expect($lineup->stats)->toBe([['name' => 'totalGoals', 'value' => 1]])
        ->and($lineup->fantasy_points)->toBe(8)
        ->and($lineup->fantasy_stats)->toBe(['marca_points' => [3, 1]]);
});

test('fantasy_points and fantasy_stats default to null', function (): void {
    $lineup = FixtureLineup::factory()->create();

    expect($lineup->fantasy_points)->toBeNull()
        ->and($lineup->fantasy_stats)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FixtureLineupTest`
Expected: FAIL — `fantasy_points`/`fantasy_stats` aren't columns yet.

- [ ] **Step 3: Write the migration**

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
            $table->integer('fantasy_points')->nullable()->after('stats');
            $table->json('fantasy_stats')->nullable()->after('fantasy_points');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->dropColumn(['fantasy_points', 'fantasy_stats']);
        });
    }
};
```

- [ ] **Step 4: Update the `FixtureLineup` model**

In `app/Models/FixtureLineup.php`:

- Add to the docblock, after `@property-read array<int, array<string, mixed>> $stats`:
  ```php
  /**
   * @property-read int|null $fantasy_points
   * @property-read array<string, mixed>|null $fantasy_stats
   */
  ```
  (merge into the existing docblock rather than adding a second one).
- Add `'fantasy_points', 'fantasy_stats'` to the `#[Fillable(...)]` array.
- Add to `casts()`: `'fantasy_points' => 'int', 'fantasy_stats' => 'array',`.

- [ ] **Step 5: Update `FixtureLineupFactory`**

In `database/factories/FixtureLineupFactory.php`, add to `definition()`'s returned array (after `'stats' => [],`):

```php
            'fantasy_points' => null,
            'fantasy_stats' => null,
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=FixtureLineupTest`
Expected: PASS

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_30_100300_add_fantasy_points_and_stats_to_fixture_lineups_table.php app/Models/FixtureLineup.php database/factories/FixtureLineupFactory.php tests/Feature/Models/FixtureLineupTest.php
git commit -m "feat: add fantasy_points/fantasy_stats columns to fixture_lineups"
```

---

### Task 5: `manager_lineup_players` drops `points`/`stats`, gains `fixture_id`

**Files:**
- Create: `database/migrations/2026_08_30_100400_replace_points_stats_with_fixture_id_on_manager_lineup_players_table.php`
- Modify: `app/Models/ManagerLineupPlayer.php`
- Modify: `database/factories/ManagerLineupPlayerFactory.php`
- Modify: `tests/Feature/Models/ManagerLineupPlayerTest.php`

**Interfaces:**
- Produces: `ManagerLineupPlayer.fixture_id: int|null` (FK to `fixtures.id`), `ManagerLineupPlayer::fixtureLineup(): HasOne<FixtureLineup>` (matches on `fixture_id` + `player_id` together). `points`/`stats` columns and casts are removed entirely — nothing later in this plan writes or reads `ManagerLineupPlayer.points`/`.stats` directly; `fantasy_points`/`fantasy_stats` for a lineup player come from `->fixtureLineup?->fantasy_points` / `->fixtureLineup?->fantasy_stats`.

- [ ] **Step 1: Write the failing test — replaces the existing test in `ManagerLineupPlayerTest.php`**

Replace the current single test in `tests/Feature/Models/ManagerLineupPlayerTest.php` with:

```php
<?php

use App\Enums\PlayerPosition;
use App\Models\FixtureLineup;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;

test('belongs to a lineup and player, and has no points/stats columns of its own', function (): void {
    $lineup = ManagerLineup::factory()->create();
    $player = Player::factory()->create();
    $lineupPlayer = ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'position' => PlayerPosition::Goalkeeper,
    ]);

    expect($lineupPlayer->lineup)->toBeInstanceOf(ManagerLineup::class)
        ->and($lineupPlayer->player)->toBeInstanceOf(Player::class)
        ->and($lineupPlayer->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($player->lineupPlayers)->toHaveCount(1);
});

test('fixtureLineup resolves the matching FixtureLineup row by fixture_id and player_id', function (): void {
    $fixture = Fixture::factory()->create();
    $player = Player::factory()->create();
    $matchingLineup = FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'fantasy_points' => 8,
    ]);
    // A different player's row on the same fixture must not match.
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id]);

    $lineupPlayer = ManagerLineupPlayer::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
    ]);

    expect($lineupPlayer->fixtureLineup)->not->toBeNull()
        ->and($lineupPlayer->fixtureLineup->id)->toBe($matchingLineup->id)
        ->and($lineupPlayer->fixtureLineup->fantasy_points)->toBe(8);
});

test('fixtureLineup is null when fixture_id is not yet set (lineup set before the match is synced)', function (): void {
    $lineupPlayer = ManagerLineupPlayer::factory()->create(['fixture_id' => null]);

    expect($lineupPlayer->fixtureLineup)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=ManagerLineupPlayerTest`
Expected: FAIL — `fixture_id` isn't a column, `fixtureLineup()` relation doesn't exist, and the factory still sets `points`.

- [ ] **Step 3: Write the migration**

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
        Schema::table('manager_lineup_players', function (Blueprint $table): void {
            $table->dropColumn(['points', 'stats']);
            $table->foreignId('fixture_id')->nullable()->after('player_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('manager_lineup_players', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fixture_id');
            $table->integer('points')->nullable(false)->default(0);
            $table->json('stats')->nullable();
        });
    }
};
```

- [ ] **Step 4: Update the `ManagerLineupPlayer` model**

Replace `app/Models/ManagerLineupPlayer.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\ManagerLineupPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read int $id
 * @property-read int $manager_lineup_id
 * @property-read int $player_id
 * @property-read int|null $fixture_id
 * @property-read PlayerPosition $position
 * @property bool $match_finished Computed at query time by SeasonManagersController; not a database column.
 */
#[UseFactory(ManagerLineupPlayerFactory::class)]
#[Table(name: 'manager_lineup_players', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['manager_lineup_id', 'player_id', 'fixture_id', 'position'])]
class ManagerLineupPlayer extends Model
{
    /** @use HasFactory<ManagerLineupPlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<ManagerLineup, $this> */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(ManagerLineup::class, 'manager_lineup_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return HasOne<FixtureLineup, $this> */
    public function fixtureLineup(): HasOne
    {
        return $this->hasOne(FixtureLineup::class, 'player_id', 'player_id')
            ->whereColumn('fixture_lineups.fixture_id', 'manager_lineup_players.fixture_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'manager_lineup_id' => 'int',
            'player_id' => 'int',
            'fixture_id' => 'int',
            'position' => PlayerPosition::class,
        ];
    }
}
```

- [ ] **Step 5: Update `ManagerLineupPlayerFactory`**

Replace `database/factories/ManagerLineupPlayerFactory.php`'s `definition()` return array with:

```php
        return [
            'manager_lineup_id' => ManagerLineup::factory(),
            'player_id' => Player::factory(),
            'fixture_id' => null,
            'position' => $this->faker->randomElement(PlayerPosition::cases()),
        ];
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=ManagerLineupPlayerTest`
Expected: PASS

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: FAIL, expected — every place still reading `ManagerLineupPlayer.points`/`.stats` (`FixturesController`, `PlayersController`, `SyncCurrentSeasonManagerLineups`, their tests) errors on the now-missing property. This is fixed in Tasks 8, 11, 12, 16 — do not attempt to fix those call sites here.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_30_100400_replace_points_stats_with_fixture_id_on_manager_lineup_players_table.php app/Models/ManagerLineupPlayer.php database/factories/ManagerLineupPlayerFactory.php tests/Feature/Models/ManagerLineupPlayerTest.php
git commit -m "feat: replace manager_lineup_players.points/stats with a fixture_id link to FixtureLineup"
```

---

### Task 6: Eliminate `player_scores`

**Files:**
- Create: `database/migrations/2026_08_30_100500_drop_player_scores_table.php`
- Delete: `app/Models/PlayerScore.php`
- Delete: `database/factories/PlayerScoreFactory.php`
- Delete: `tests/Feature/Models/PlayerScoreTest.php`
- Modify: `app/Models/Fixture.php`
- Modify: `app/Models/Player.php`

**Interfaces:**
- Removes: `PlayerScore` model/factory, `Fixture::playerScores()`, `Player::scores()`. Nothing later in this plan references `App\Models\PlayerScore`.

- [ ] **Step 1: Delete the model, factory, and test**

```bash
git rm app/Models/PlayerScore.php database/factories/PlayerScoreFactory.php tests/Feature/Models/PlayerScoreTest.php
```

- [ ] **Step 2: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('player_scores');
    }

    public function down(): void
    {
        Schema::create('player_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->integer('points')->nullable(false)->default(0);
            $table->json('stats')->nullable(false);
            $table->boolean('ideal_formation')->nullable(false)->default(false);
            $table->unique(['player_id', 'fixture_id']);
        });
    }
};
```

Add `use Illuminate\Database\Schema\Blueprint;` to the migration's `use` block (needed by `down()`).

- [ ] **Step 3: Remove `Fixture::playerScores()`**

In `app/Models/Fixture.php`, delete this method entirely:

```php
    /** @return HasMany<PlayerScore, $this> */
    public function playerScores(): HasMany
    {
        return $this->hasMany(PlayerScore::class);
    }
```

- [ ] **Step 4: Remove `Player::scores()`**

In `app/Models/Player.php`, delete this method entirely:

```php
    /** @return HasMany<PlayerScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(PlayerScore::class);
    }
```

Also delete the docblock line `@property-read int $points Computed at query time from the current season's PlayerSeason; not a database column.` — no, leave that one, it's unrelated (`PlayerSeason`, not `PlayerScore`). Only remove the `scores()` method above.

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: FAIL, expected — every remaining `use App\Models\PlayerScore;` import (controllers, concerns, other tests) now errors on a missing class. This is fixed in Tasks 7, 8, 9, 11, 12, 13, 16 — do not attempt to fix those call sites here.

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: eliminate player_scores — fantasy points/stats now live on fixture_lineups"
```

---

### Task 7: Rewrite `AttachesRecentScores` to read from `FixtureLineup`

**Files:**
- Modify: `app/Http/Controllers/Concerns/AttachesRecentScores.php`

**Interfaces:**
- Consumes: `FixtureLineup.fantasy_points` (Task 4), `ManagerLineupPlayer.fixture_id` (Task 5).
- Produces: same public behavior as before — `attachRecentScores(Collection $players, Season $season, ?int $seasonManagerId = null)` still sets `recent_scores`, `recent_scores_finished`, and (when `$seasonManagerId` given) `recent_scores_used` on each player, identically to today. Only the internal data source changes. `PlayersController` and `SeasonManagersController` (both already `use AttachesRecentScores`) need no changes for this task — their call sites are untouched.

- [ ] **Step 1: Replace the trait**

Replace `app/Http/Controllers/Concerns/AttachesRecentScores.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Support\Collection;

trait AttachesRecentScores
{
    /**
     * Attaches each player's points for their team's last 3 finished matches (oldest
     * first, ordered by fixture date). Unlike a plain "last 3 FixtureLineup rows" lookup,
     * this is based on the team's actual fixtures — a finished match the player wasn't
     * called up for still takes its slot in the sequence (with a null score), instead of
     * being silently skipped in favor of an older match. `recent_scores_finished` marks,
     * per slot, whether a real finished fixture exists there at all — false only means
     * "the team hasn't played that many matches yet", never "not called up".
     *
     * When $seasonManagerId is given (the manager ficha, where "used by this manager" is
     * a meaningful question), also attaches `recent_scores_used`: for each of the same
     * 3 jornadas, whether the player was actually in that manager's lineup that week, as
     * opposed to scoring those points while benched or not yet owned.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachRecentScores(Collection $players, Season $season, ?int $seasonManagerId = null): void
    {
        $playerIds = $players->pluck('id')->all();
        $teamIds = $players->pluck('team_id')->unique()->all();

        /** @var array<int, Collection<int, Fixture>> $fixturesByTeam */
        $fixturesByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds))
            ->get(['id', 'week_number', 'date', 'team_local_id', 'team_guest_id'])
            ->each(function (Fixture $fixture) use ($teamIds, &$fixturesByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true)) {
                        $fixturesByTeam[$teamId][] = $fixture;
                    }
                }
            });

        $scoresByPlayer = FixtureLineup::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->get(['player_id', 'fixture_id', 'fantasy_points'])
            ->groupBy('player_id')
            ->map(fn (Collection $rows) => $rows->keyBy('fixture_id'));

        $usedWeeksByPlayer = $seasonManagerId === null
            ? collect()
            : ManagerLineupPlayer::query()
                ->whereIn('player_id', $playerIds)
                ->whereHas('lineup', fn ($query) => $query->where('season_manager_id', $seasonManagerId))
                ->with('lineup:id,week_number')
                ->get()
                ->groupBy('player_id')
                ->map(fn (Collection $rows) => $rows->pluck('lineup.week_number')->all());

        $players->each(function (Player $player) use ($fixturesByTeam, $scoresByPlayer, $usedWeeksByPlayer, $seasonManagerId): void {
            $recentFixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortByDesc(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->values();

            $playerScores = $scoresByPlayer->get($player->id) ?? collect();

            $points = $recentFixtures
                ->map(fn (Fixture $fixture): ?int => $playerScores->get($fixture->id)?->fantasy_points)
                ->all();
            $finished = array_fill(0, count($points), true);

            /** @var array<int, int|null> $paddedPoints */
            $paddedPoints = array_pad($points, 3, null);

            /** @var array<int, bool> $paddedFinished */
            $paddedFinished = array_pad($finished, 3, false);

            $player->recent_scores = $paddedPoints;
            $player->recent_scores_finished = $paddedFinished;

            if ($seasonManagerId === null) {
                return;
            }

            $usedWeeks = $usedWeeksByPlayer->get($player->id, []);
            $used = $recentFixtures
                ->map(fn (Fixture $fixture): bool => in_array($fixture->week_number, $usedWeeks, true))
                ->all();

            /** @var array<int, bool|null> $paddedUsed */
            $paddedUsed = array_pad($used, 3, null);
            $player->recent_scores_used = $paddedUsed;
        });
    }
}
```

The only change from the original is `PlayerScore` → `FixtureLineup` (with `points` → `fantasy_points`); the `ManagerLineupPlayer`/`lineup.week_number` path for `recent_scores_used` is untouched since `ManagerLineupPlayer.lineup` still works exactly as before.

- [ ] **Step 2: Run the full test suite**

Run: `php artisan test`
Expected: Still has failures from Task 6's removed `PlayerScore` imports elsewhere (`FixturesController`, `PlayersController`, and their tests) — this task only fixes the trait itself. `HomeControllerTest` (which exercises this trait transitively) still fails until its own `PlayerScore::factory()` call is migrated in Task 13.

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Concerns/AttachesRecentScores.php
git commit -m "feat: source AttachesRecentScores from FixtureLineup instead of PlayerScore"
```

---

### Task 8: Rewrite `FixturesController::show()`

**Files:**
- Modify: `app/Http/Controllers/FixturesController.php`

**Interfaces:**
- Consumes: `FixtureLineup.fantasy_points`/`.fantasy_stats` (Task 4), `ManagerLineupPlayer.fixture_id` (Task 5).
- Removes: the `scores` Inertia prop entirely (the Fantasy-only fallback list is dropped per the confirmed decision — the frontend now shows its existing "no data yet" empty state whenever `lineups` is empty, exactly like the `scheduled`-state case already does).
- Produces: `lineups.*.points`/`.dazn_points` keep the exact same JSON keys as today, now sourced from `fantasy_points`/`fantasy_stats` instead of a joined `PlayerScore`. New key `lineups.*.stats` (the Fantasy stat breakdown, `fantasy_stats` renamed to plain `stats` in the JSON — see Task 9 for why: the modal needs this and there's no collision, since worldcup26's raw `stats` was never exposed to this prop). `lineups.*.lineup_manager` keeps its shape (`SeasonManager|null`), now resolved via `ManagerLineupPlayer.fixture_id` directly instead of a week/season join.

- [ ] **Step 1: Write the failing tests — append to `FixturesControllerTest.php`**

```php
test('lineup entries carry fantasy_points/fantasy_stats as points/stats, sourced from FixtureLineup directly', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $home = $fixture->localTeam;
    $player = Player::factory()->create(['team_id' => $home->id, 'position' => PlayerPosition::Defender]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'starter' => true,
        'position' => 'Left Back',
        'jersey' => '3',
        'fantasy_points' => 8,
        'fantasy_stats' => ['marca_points' => [3, 1]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->missing('scores')
        ->where('lineups.0.points', 8)
        ->where('lineups.0.stats', ['marca_points' => [3, 1]])
    );
});

test('resolves lineup_manager via ManagerLineupPlayer.fixture_id, not week_number', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $fixture->localTeam->id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => $fixture->week_number]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.lineup_manager.id', $seasonManager->id)
    );
});

test('does not resolve lineup_manager for a ManagerLineupPlayer whose fixture_id points elsewhere', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $otherFixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $fixture->localTeam->id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $otherFixture->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.lineup_manager', null)
    );
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=FixturesControllerTest`
Expected: FAIL (also still failing on the pre-existing `PlayerScore`-based tests from before this task — that's expected, fixed in Task 9).

- [ ] **Step 3: Rewrite the controller**

Replace `app/Http/Controllers/FixturesController.php`'s `use` block, `show()`, and `presentLineup()` as follows.

Replace the `use` block (drop `PlayerScore`, keep `ManagerLineupPlayer`):

```php
use App\Enums\FixtureState;
use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Season;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
```

(`App\Enums\PlayerPosition` and the `POSITION_ORDER` constant were only used to sort the now-removed `$scores` collection — delete the `use App\Enums\PlayerPosition;` import and the `POSITION_ORDER` constant declaration entirely.)

Replace `show()`:

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

        $fixtureLineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->with('player.team', 'counterpartPlayer')
            ->get()
            ->sortBy([
                fn (FixtureLineup $a, FixtureLineup $b): int => $this->lineOrder($a->position) <=> $this->lineOrder($b->position),
                fn (FixtureLineup $a, FixtureLineup $b): int => $a->jersey <=> $b->jersey,
            ])
            ->values();

        $this->attachCurrentSeason($fixtureLineups->pluck('player')->filter(), $fixture->season_id);

        // Which manager fielded each player in their lineup this jornada — distinct
        // from ownership, since an owner can bench a player they still own.
        $lineupManagersByPlayer = ManagerLineupPlayer::query()
            ->where('fixture_id', $fixture->id)
            ->whereIn('player_id', $fixtureLineups->pluck('player_id')->filter())
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('player_id');

        $lineups = $fixtureLineups
            ->map(fn (FixtureLineup $lineup): array => $this->presentLineup($lineup, $fixture, $lineupManagersByPlayer, $fixtureLineups));

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
            'lineups' => $lineups,
            'events' => $events,
            'team_stats' => $this->teamStats($fixtureLineups, $fixture),
        ]);
    }
```

Replace `presentLineup()`:

```php
    /**
     * @param  Collection<int, ManagerLineupPlayer>  $lineupManagersByPlayer
     * @param  Collection<int, FixtureLineup>  $fixtureLineups
     * @return array<string, mixed>
     */
    private function presentLineup(FixtureLineup $lineup, Fixture $fixture, Collection $lineupManagersByPlayer, Collection $fixtureLineups): array
    {
        $isLocal = $lineup->team_id === $fixture->team_local_id;

        // DAZN ratings are only meaningful once the match is over.
        $daznPoints = $fixture->state === FixtureState::Finished
            ? ($lineup->fantasy_stats['marca_points'][1] ?? null)
            : null;

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
            'points' => $lineup->fantasy_points,
            'stats' => $lineup->fantasy_stats,
            'dazn_points' => $daznPoints,
            'x' => $lineup->starter ? $this->pitchX($lineup->position, $isLocal) : null,
            'y' => $lineup->starter ? $this->pitchY($lineup, $fixtureLineups) : null,
            'lineup_manager' => $lineup->player_id !== null ? $lineupManagersByPlayer->get($lineup->player_id)?->lineup?->seasonManager : null,
        ];
    }
```

`statValue()`, `pitchX()`, `pitchY()`, `teamStats()`, `sideOrder()`, `lineOrder()` are unchanged — leave them exactly as they are.

- [ ] **Step 4: Run the tests to verify the new ones pass**

Run: `php artisan test --filter=FixturesControllerTest`
Expected: the 3 new tests from Step 1 PASS. Every pre-existing `PlayerScore`-based test in this file still FAILS — that's Task 9's job, not this one.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FixturesController.php
git commit -m "feat: rewrite FixturesController::show() to read fantasy points/stats and lineup_manager off FixtureLineup"
```

---

### Task 9: Migrate `FixturesControllerTest.php` to the new schema, and drop the fallback-list tests

**Files:**
- Modify: `tests/Feature/Http/Controllers/FixturesControllerTest.php`

**Interfaces:**
- Consumes: Task 8's controller.

This task is mechanical: every `PlayerScore::factory()->create([...])` call in this file becomes a `FixtureLineup::factory()->create([...])` call with the same `fixture_id`/`player_id`/`team_id` and `'points' => N` renamed to `'fantasy_points' => N` (drop `ideal_formation` if present — it's never set in this file's existing calls). Every `ManagerLineupPlayer::factory()->create(['manager_lineup_id' => ..., 'player_id' => ...])` call that exists to test `lineup_manager` resolution needs `'fixture_id' => $fixture->id` added (or `$otherWeekLineup`'s matching fixture, for the "different week doesn't match" test — see Step 3 below).

- [ ] **Step 1: Update the imports**

Remove `use App\Models\PlayerScore;`. Keep `use App\Models\ManagerLineupPlayer;` and add `use App\Models\FixtureLineup;` if it isn't already imported (it already is, per the existing `use App\Models\FixtureLineup;` line — verify, don't duplicate).

- [ ] **Step 2: Migrate every `PlayerScore::factory()` call**

Find every occurrence via `Grep` (`pattern: "PlayerScore::factory"`, this file) and replace each with the `FixtureLineup::factory()` equivalent. Concretely, for the block at (current) lines 132-138:

```php
    $strikerScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $striker->id, 'points' => 3]);
    $goalkeeperScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $goalkeeper->id, 'points' => 1]);
    $midfieldScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $midfield->id, 'points' => 9]);
    $defenderScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $defender->id, 'points' => 5]);
```

becomes:

```php
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $striker->id, 'fantasy_points' => 3]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $goalkeeper->id, 'fantasy_points' => 1]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $midfield->id, 'fantasy_points' => 9]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $defender->id, 'fantasy_points' => 5]);
```

(drop the `$xScore =` assignment unless the variable is referenced later in that same test — check before deleting; if referenced, keep the assignment and update its usages the same way, e.g. `$strikerScore->points` → `$strikerScore->fantasy_points`).

Apply the same `PlayerScore::factory()->create([...'points' => N...])` → `FixtureLineup::factory()->create([...'fantasy_points' => N...])` substitution (dropping `'points'` key name in favor of `'fantasy_points'`, dropping any `'ideal_formation'` key entirely) to every remaining occurrence in the file — this repo has ~13 more (lines 159, 178, 179, 197, 212, 233, 250, 284, 347, plus the two around line 767 already covered by Step 3 below). Where a test builds a full lineup entry to check controller output shape (e.g. around the existing lines 265-360, the tests already using `FixtureLineup::factory()->create([...])` directly for pitch coordinates), check whether it also has a *separate* `PlayerScore::factory()->create([...])` call for the same player — if so, merge the `fantasy_points`/`fantasy_stats` keys into that single `FixtureLineup::factory()->create([...])` call instead of creating two rows for one player-fixture pair (the unique constraint on `fixture_lineups` would reject two rows for the same `fixture_id`+`player_id` anyway).

- [ ] **Step 3: Update `ManagerLineupPlayer::factory()` calls used for `lineup_manager` tests**

For each of the (current) three occurrences (lines 216, 254, 770) that create a `ManagerLineupPlayer` to test `lineup_manager` resolution, add `'fixture_id' => $fixture->id,` — except the one testing "a different week's lineup doesn't leak in" (around line 253-254, `$otherWeekLineup`), which should now read: **a `ManagerLineupPlayer` whose `fixture_id` points at a different fixture must not resolve** (this replaces the old "different week" framing, since resolution is now by `fixture_id` directly, not week number). Rename that test's title accordingly if it still says "week" and change its setup to create a second `Fixture` and point the `ManagerLineupPlayer` at that one instead of the one under test — mirroring the "does not resolve lineup_manager for a ManagerLineupPlayer whose fixture_id points elsewhere" test already added in Task 8, Step 1 (this one may now be a near-duplicate — if so, delete the older one instead of keeping both, since Task 8 already covers this case).

- [ ] **Step 4: Rename the test at (current) line 363**

`test('nulls dazn_points for a lineup entry with no PlayerScore, regardless of fixture state', ...)` — rename to `test('nulls dazn_points for a lineup entry with no fantasy_stats, regardless of fixture state', ...)` and change its `PlayerScore::factory()` setup (if any — re-check this test's body, since the title mentions "no PlayerScore" which may mean it deliberately creates *no* score row at all, in which case just drop the reference to `PlayerScore` in the title/setup with no factory call needed).

- [ ] **Step 5: Delete the fallback-list tests**

Search this file for any test that asserts on a `scores` Inertia prop directly (e.g. `->has('scores', ...)`, `->where('scores.0...`) or that exercises the "no lineup data yet, fall back to Fantasy scores" behavior (likely titled something like "falls back to the Fantasy scores list..."). Delete those tests entirely — that behavior no longer exists (Task 8 dropped the `scores` prop). Also add, if not already covered by Task 8 Step 1's new tests:

```php
test('lineups prop is empty when no FixtureLineup rows are synced yet, with no scores fallback', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 0)
        ->missing('scores')
    );
});
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=FixturesControllerTest`
Expected: PASS. If any assertion still fails, it's referencing a field/behavior this task's mapping didn't anticipate — read the failure, apply the same `points`→`fantasy_points`/`stats`→`fantasy_stats` mapping, and fix it before moving on.

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: only failures remaining should be in `PlayersControllerTest`, `SeasonManagersControllerTest`, `HomeControllerTest`, the sync-command tests, and `resources/js` (not run by `php artisan test`) — all fixed in later tasks.

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/Http/Controllers/FixturesControllerTest.php
git commit -m "test: migrate FixturesControllerTest to FixtureLineup-sourced fantasy points/stats"
```

---

### Task 10: Frontend — `fixtures/show.tsx`, drop the scores fallback and `PlayerScore` usage

**Files:**
- Modify: `resources/js/types/models.ts`
- Modify: `resources/js/pages/fixtures/show.tsx`
- Delete: `resources/js/components/hq-fixture-score-list.tsx`

**Interfaces:**
- Consumes: Task 8's `lineups` prop shape (`points`, `stats`, `dazn_points`, `lineup_manager` — same keys, `stats` is new).
- Produces: `FixtureLineupEntry` gains a `stats: JornadaStats | null` field. `FixtureShow` no longer receives or uses a `scores` prop.

- [ ] **Step 1: Add `stats` to `FixtureLineupEntry`**

In `resources/js/types/models.ts`, find the `FixtureLineupEntry` interface (added in the fitxa-partit-redesign phase) and add a `stats` field. Check whether `JornadaStats` is already imported/defined in this file (it's used by `PlayerScore`/`PlayerFichaScore`) — reuse that type, don't redefine it:

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
    stats: JornadaStats | null;
    dazn_points: number | null;
    x: number | null;
    y: number | null;
    lineup_manager: SeasonManager | null;
}
```

(add `stats: JornadaStats | null;` after `points`; the other fields already exist — only insert the new line and confirm `lineup_manager` is already present, since Task 8 kept that key unchanged).

- [ ] **Step 2: Remove `ideal_formation` from `PlayerScore` and `PlayerFichaScore`**

In the same file, delete the `ideal_formation: boolean;` line from both the `PlayerScore` interface and the `PlayerFichaScore` interface. (`PlayerScore` itself — the fixtures-page-scoped one — is about to become unused after Step 3 below; leaving the interface declared but unused is fine for now since `PlayerFichaScore`, used by `players/show.tsx`, still needs the type minus `ideal_formation`. If a later type-check flags `PlayerScore` as unused, that's expected — TypeScript doesn't error on unused exported interfaces.)

- [ ] **Step 3: Rewrite `resources/js/pages/fixtures/show.tsx`**

Remove the `HqFixtureScoreList` import and the `PlayerScore` import from `@/types/models` (keep `FixtureLineupEntry`, `FixtureEventEntry`, `FixtureTeamStat`, `Fixture`). Add an import for `HqPlayerStatsEntry` from `@/components/hq-player-stats-modal` (it's already exported there).

Replace the props interface:

```ts
interface FixtureShowProps {
    fixture: Fixture;
    weekFixtures: Fixture[];
    lineups: FixtureLineupEntry[];
    events: FixtureEventEntry[];
    team_stats: FixtureTeamStat[];
    [key: string]: unknown;
}
```

Replace the component's destructuring and state (drop `scores`, rename `selectedScore`→`selectedEntry`, drop `scoresByPlayerId`):

```ts
export default function FixtureShow({
    fixture,
    weekFixtures,
    lineups,
    events,
    team_stats,
}: FixtureShowProps) {
    const [activeTab, setActiveTab] = useState<'bench' | 'stats' | 'timeline'>(
        'bench',
    );
    const [selectedEntry, setSelectedEntry] = useState<FixtureLineupEntry | null>(
        null,
    );
    const isLive = isLiveFixtureState(fixture.state);
    const hasScore = isLive || fixture.state === 'finished';

    const handleSelectLineupEntry = (entry: FixtureLineupEntry) => {
        if (entry.player) {
            setSelectedEntry(entry);
        }
    };
```

Replace the `fixture.state === 'scheduled' ? ... : lineups.length === 0 ? ... : ...` branch — both the scheduled and empty-lineups cases now render the same empty state:

```tsx
                    {fixture.state === 'scheduled' || lineups.length === 0 ? (
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
```

(the rest of that branch — the pitch, legend, tabs — is unchanged; only the `lineups.length === 0` branch's content is deleted, replaced by falling into the same empty-state JSX as the scheduled case).

Replace the `HqPlayerStatsModal` invocation at the bottom — build the entry from `selectedEntry` (a `FixtureLineupEntry`) instead of `selectedScore` (a `PlayerScore`):

```tsx
            <HqPlayerStatsModal
                entry={
                    selectedEntry && selectedEntry.player
                        ? ({
                              player: selectedEntry.player,
                              team:
                                  selectedEntry.team_id === fixture.local_team.id
                                      ? fixture.local_team
                                      : fixture.guest_team,
                              points: selectedEntry.points ?? 0,
                              daznPoints:
                                  fixture.state === 'finished'
                                      ? (selectedEntry.dazn_points ?? undefined)
                                      : undefined,
                              stats: selectedEntry.stats ?? ({} as JornadaStats),
                              lineupManager: selectedEntry.lineup_manager,
                              matchPosition: selectedEntry.position,
                              subMinute:
                                  selectedEntry.sub_minute === null
                                      ? null
                                      : {
                                            minute: selectedEntry.sub_minute,
                                            direction: selectedEntry.subbed_out
                                                ? ('out' as const)
                                                : ('in' as const),
                                        },
                          } satisfies HqPlayerStatsEntry)
                        : null
                }
                onClose={() => setSelectedEntry(null)}
            />
```

Add `JornadaStats` to the `@/types/models` import list (needed for the `{} as JornadaStats}` fallback above).

- [ ] **Step 4: Delete the now-unused score-list component**

```bash
git rm resources/js/components/hq-fixture-score-list.tsx
```

- [ ] **Step 5: Type-check**

Run: `npm run build`
Expected: no type errors. If `PlayerScore` (the interface, not the deleted model) is flagged as unused by a lint rule (not `tsc` itself — `tsc` doesn't error on unused exported types), leave it; it's still used by nothing after this task but `players/show.tsx` still needs `PlayerFichaScore`, a separate type left untouched here.

- [ ] **Step 6: Manual browser check**

Per the `run` skill: start the app, open a fixture page with synced `FixtureLineup` rows (`fantasy_points` set) and confirm points/DAZN/stats-modal still render; open a fixture with no `FixtureLineup` rows and confirm it shows the empty state (not a blank/broken fallback list).

- [ ] **Step 7: Commit**

```bash
git add resources/js/types/models.ts resources/js/pages/fixtures/show.tsx
git rm resources/js/components/hq-fixture-score-list.tsx
git commit -m "feat: drop the fixture-page Fantasy-only fallback list, read points/stats/lineup_manager off FixtureLineupEntry"
```

---

### Task 11: Rewrite `PlayersController::show()`

**Files:**
- Modify: `app/Http/Controllers/PlayersController.php`

**Interfaces:**
- Consumes: `FixtureLineup.fantasy_points`/`.fantasy_stats` (Task 4), `Player::fixtureLineups()` — new relation added in this task.
- Produces: the `scores` prop (player ficha, `PlayerFichaScore[]` on the frontend) keeps its exact JSON shape (`id, team_id, team, points, stats, fixture, lineup_manager` — `ideal_formation` dropped, matching Task 10 Step 2's frontend type change), now built from `FixtureLineup` instead of `PlayerScore`.

- [ ] **Step 1: Add `Player::fixtureLineups()`**

In `app/Models/Player.php`, add (near the other `HasMany` relations, e.g. after `lineupPlayers()`):

```php
    /** @return HasMany<FixtureLineup, $this> */
    public function fixtureLineups(): HasMany
    {
        return $this->hasMany(FixtureLineup::class);
    }
```

Add `use App\Models\FixtureLineup;` — wait, `FixtureLineup` is in the same `App\Models` namespace, no import needed; just add the method and its `@return HasMany<FixtureLineup, $this>` docblock.

- [ ] **Step 2: Write the failing test — append to `PlayersControllerTest.php`**

```php
test('player ficha scores prop is built from FixtureLineup, not PlayerScore', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'team_id' => $player->team_id,
        'fantasy_points' => 9,
        'fantasy_stats' => ['marca_points' => [3, 2]],
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('scores', 1)
        ->where('scores.0.points', 9)
        ->where('scores.0.stats', ['marca_points' => [3, 2]])
    );
});

test('player ficha lineup_manager is resolved via ManagerLineupPlayer.fixture_id', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'team_id' => $player->team_id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager.id', $seasonManager->id)
    );
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=PlayersControllerTest`
Expected: FAIL — `$player->scores()` (the deleted relation) and the `PlayerScore` import error.

- [ ] **Step 4: Rewrite the controller**

Replace the `use` block's `App\Models\ManagerLineupPlayer` line stays; remove `use App\Models\PlayerScore;`. Replace the `scores`-building block inside `show()` (currently `$scores = $player->scores()->...` through the `$scores->each(...)` block) with:

```php
        $scores = $player->fixtureLineups()
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with(['fixture.localTeam', 'fixture.guestTeam', 'team'])
            ->get()
            ->sortBy(fn (FixtureLineup $lineup) => $lineup->fixture->week_number)
            ->values()
            ->map(fn (FixtureLineup $lineup): array => [
                'id' => $lineup->id,
                'team_id' => $lineup->team_id,
                'team' => $lineup->team,
                'points' => $lineup->fantasy_points,
                'stats' => $lineup->fantasy_stats,
                'fixture' => $lineup->fixture,
                'lineup_manager' => null,
            ]);

        // Which manager fielded this player in their lineup each jornada — distinct
        // from ownership, since an owner can bench a player they still own.
        $lineupManagersByFixture = ManagerLineupPlayer::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', $scores->pluck('fixture.id')->filter())
            ->whereHas('lineup.seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('fixture_id');

        $scores = $scores->map(function (array $score) use ($lineupManagersByFixture): array {
            $score['lineup_manager'] = $lineupManagersByFixture->get($score['fixture']->id)?->lineup?->seasonManager;

            return $score;
        });
```

Add `use App\Models\FixtureLineup;` to the `use` block.

Note: `$scores` is now a plain array-of-arrays via `->map()`, not an Eloquent collection of models — this is deliberate (the old `PlayerScore` rows were real models with a `lineup_manager` virtual property bolted on; here there's no single model that naturally carries both `FixtureLineup`'s columns and a computed `lineup_manager`, so the controller builds the array shape directly). Inertia serializes this identically either way.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=PlayersControllerTest`
Expected: the 2 new tests PASS. Every pre-existing `PlayerScore`-based test in this file still FAILS — Task 12's job.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Player.php app/Http/Controllers/PlayersController.php
git commit -m "feat: rewrite PlayersController::show() scores/lineup_manager to read from FixtureLineup"
```

---

### Task 12: Migrate `PlayersControllerTest.php` to the new schema

**Files:**
- Modify: `tests/Feature/Http/Controllers/PlayersControllerTest.php`

**Interfaces:**
- Consumes: Task 11's controller.

Same mechanical transformation as Task 9, applied to this file's ~13 `PlayerScore::factory()->create([...'points' => N...])` calls (lines 343-345, 365-368, 385, 413, 435, 618-619, 638, 653, 674, 691 per the grep in this plan's investigation — re-grep before editing, since line numbers shift as earlier edits land) and its 2 `ManagerLineupPlayer::factory()->create(['manager_lineup_id' => ..., 'player_id' => ...])` calls (lines 657, 695) used for `lineup_manager` resolution tests, which need `'fixture_id' => $fixture->id` added (for line 657's "resolves lineup_manager" case) or a *different* fixture's id (for line 695's "other season manager doesn't leak in" case — that one already uses an unrelated `$otherSeasonManager`/`$otherLineup`, so just add `'fixture_id' => $fixture->id` there too, since the isolation being tested is by season/manager, not by fixture).

- [ ] **Step 1: Update the imports**

Remove `use App\Models\PlayerScore;`. Add `use App\Models\FixtureLineup;` if not already present.

- [ ] **Step 2: Migrate every `PlayerScore::factory()` call**

Use `Grep` (`pattern: "PlayerScore::factory"`, this file) to find every occurrence and apply the same substitution as Task 9 Step 2: `PlayerScore::factory()->create(['player_id' => $id, 'fixture_id' => $id, 'points' => N])` → `FixtureLineup::factory()->create(['player_id' => $id, 'fixture_id' => $id, 'fantasy_points' => N])`. Where a call omits `points` entirely (e.g. line 638's `PlayerScore::factory()->create(['player_id' => $otherPlayer->id])`), the `FixtureLineup::factory()` equivalent needs no `fantasy_points` key either — the factory default (`null`, from Task 4) is fine.

- [ ] **Step 3: Update the 2 `ManagerLineupPlayer::factory()` calls**

Add `'fixture_id' => $fixture->id` to both (per the note above).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=PlayersControllerTest`
Expected: PASS.

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: only failures remaining should be in `SeasonManagersControllerTest`, `HomeControllerTest`, and the sync-command tests — fixed in Tasks 13-16.

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Http/Controllers/PlayersControllerTest.php
git commit -m "test: migrate PlayersControllerTest to FixtureLineup-sourced fantasy points/stats"
```

---

### Task 13: `SeasonManagersController` — attach points/stats via `ManagerLineupPlayer::fixtureLineup`

**Files:**
- Modify: `app/Http/Controllers/SeasonManagersController.php`
- Modify: `tests/Feature/Http/Controllers/SeasonManagersControllerTest.php`
- Modify: `tests/Feature/Http/Controllers/HomeControllerTest.php`

**Interfaces:**
- Consumes: `ManagerLineupPlayer::fixtureLineup()` (Task 5).
- Produces: `ManagerLineupPlayerEntry.points`/`.stats` on the frontend keep their exact shape — still computed properties on the `ManagerLineupPlayer` model, now sourced from the related `FixtureLineup` instead of stored columns.

- [ ] **Step 1: Write the failing test — append to `SeasonManagersControllerTest.php`**

```php
test('lineup player points/stats come from the linked FixtureLineup via fixture_id', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'fantasy_points' => 7,
        'fantasy_stats' => ['mins_played' => [90, 2]],
    ]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.points', 7)
        ->where('lineups.0.players.0.stats', ['mins_played' => [90, 2]])
    );
});

test('lineup player points/stats are null when fixture_id is not yet set', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'fixture_id' => null]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.points', null)
        ->where('lineups.0.players.0.stats', null)
    );
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SeasonManagersControllerTest`
Expected: FAIL — `points`/`stats` aren't set on `ManagerLineupPlayer` entries at all yet (Task 5 removed the columns; nothing attaches the computed equivalent).

- [ ] **Step 3: Add an `attachLineupPlayerScores()` helper and call it from both `index()` and `show()`**

In `app/Http/Controllers/SeasonManagersController.php`, add this private method (near `attachMatchFinished`):

```php
    /**
     * `ManagerLineupPlayer` no longer stores its own points/stats — both are
     * looked up from the linked `FixtureLineup` row (via `fixture_id`, set once
     * the lineup was saved) and attached as virtual properties, the same way
     * `attachMatchFinished` already does for `match_finished`.
     *
     * @param  Collection<int, ManagerLineup>  $lineups
     */
    private function attachLineupPlayerScores(Collection $lineups): void
    {
        $lineups->each(function (ManagerLineup $lineup): void {
            foreach ($lineup->players as $entry) {
                $entry->points = $entry->fixtureLineup?->fantasy_points;
                $entry->stats = $entry->fixtureLineup?->fantasy_stats;
            }
        });
    }
```

Add `use App\Models\ManagerLineupPlayer;` and `@property int|null $points` / `@property array<string, mixed>|null $stats` to `ManagerLineupPlayer`'s docblock in `app/Models/ManagerLineupPlayer.php` (these are now virtual/computed, same pattern as the existing `@property bool $match_finished` line — add them right after it).

In `index()`, after the existing `$this->attachMatchFinished($lineups, $season);` line, add `$this->attachLineupPlayerScores($lineups);`. Eager-load the relation needed for this to avoid N+1: change `->with(['seasonManager', 'players.player.team'])` to `->with(['seasonManager', 'players.player.team', 'players.fixtureLineup'])`.

In `show()`, after `$this->attachMatchFinished($lineupHistory, $season);`, add `$this->attachLineupPlayerScores($lineupHistory);`. Eager-load: change `->with('players.player.team')` to `->with('players.player.team', 'players.fixtureLineup')` (both occurrences in `show()` — the `$lineupHistory` query).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SeasonManagersControllerTest`
Expected: the 2 new tests PASS. Pre-existing tests in this file that still create `PlayerScore` rows still FAIL — fixed in Step 5.

- [ ] **Step 5: Migrate this file's remaining `PlayerScore`/`ManagerLineupPlayer` factory calls**

Same mechanical mapping as Task 9/12: every `PlayerScore::factory()->create(['player_id' => ..., 'fixture_id' => ..., 'points' => N])` (lines 385-386, 410-411 per this plan's investigation — re-grep before editing) becomes `FixtureLineup::factory()->create([..., 'fantasy_points' => N])`. Every `ManagerLineupPlayer::factory()->create(['manager_lineup_id' => ...])` that's meant to carry those points now additionally needs `'fixture_id' => $fixture->id` pointing at the matching `FixtureLineup` row, so `attachLineupPlayerScores()` can resolve it (lines 110, 140, 172, 302, 414, 467, 480 — check each one's surrounding test to see whether it's testing points/stats display, in which case it needs a matching `FixtureLineup` + `fixture_id`, or just testing roster/position display, in which case `fixture_id` can stay unset).

Remove `use App\Models\PlayerScore;` and add `use App\Models\FixtureLineup;` if not already present.

- [ ] **Step 6: Migrate `HomeControllerTest.php`**

Replace the one occurrence (line 173):

```php
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'points' => 9]);
```

with:

```php
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'fantasy_points' => 9]);
```

Remove `use App\Models\PlayerScore;`, add `use App\Models\FixtureLineup;`.

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: only failures remaining should be the sync-command tests (`SyncCurrentSeasonPlayerScoresTest`, `SyncLiveSeasonPlayerScoresTest`, `SyncCurrentSeasonPlayerScoreStatsTest`, `SyncLiveSeasonPlayerScoreStatsTest`, `SyncCurrentSeasonManagerLineupsTest`) — fixed in Tasks 15-16.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/SeasonManagersController.php app/Models/ManagerLineupPlayer.php tests/Feature/Http/Controllers/SeasonManagersControllerTest.php tests/Feature/Http/Controllers/HomeControllerTest.php
git commit -m "feat: attach manager lineup player points/stats from FixtureLineup via fixture_id"
```

---

### Task 14: Frontend — drop `ideal_formation` usage, verify `players/show.tsx` and season-managers pages still type-check

**Files:**
- Modify: `resources/js/pages/players/show.tsx` (only if it references `ideal_formation` — verify first)
- No other frontend changes expected — this task is a verification pass.

**Interfaces:**
- Consumes: Task 10 Step 2's already-updated `PlayerFichaScore` type (no `ideal_formation`), Task 11's wire-compatible `scores` shape, Task 13's wire-compatible `ManagerLineupPlayerEntry` shape.

- [ ] **Step 1: Confirm no frontend code reads `.ideal_formation`**

Run (via `Grep`, not shell): search `resources/js` for `ideal_formation`. Per this plan's investigation, no `.tsx` file reads it (only the two now-updated type declarations referenced it) — if this search turns up a usage, read that file and remove the reference, since the field no longer exists anywhere in the JSON.

- [ ] **Step 2: Type-check**

Run: `npm run build`
Expected: no errors. `players/show.tsx` and `season-managers/index.tsx`/`show.tsx` need no code changes — their prop shapes (`PlayerFichaScore`, `ManagerLineupPlayerEntry`) stayed wire-compatible through Tasks 11 and 13.

- [ ] **Step 3: Manual browser check**

Per the `run` skill: open a player ficha with score history and confirm the score list renders; open a season-manager ficha/index with a lineup that has `fixture_id` set on some entries and unset on others, and confirm points/stats show for the linked ones and blank/dash for the unlinked ones (whatever the existing UI already does for `points: null`).

- [ ] **Step 4: Commit (only if Step 1 found something to fix)**

```bash
git add resources/js/pages/players/show.tsx
git commit -m "fix: remove dangling ideal_formation reference"
```

(skip this commit entirely if Step 1 found nothing to change).

---

### Task 15: Delete the sync commands that can no longer write anything

**Files:**
- Delete: `app/Console/Commands/SyncCurrentSeasonPlayerScores.php`
- Delete: `app/Console/Commands/SyncLiveSeasonPlayerScores.php`
- Delete: `app/Console/Commands/SyncCurrentSeasonPlayerScoreStats.php`
- Delete: `app/Console/Commands/SyncLiveSeasonPlayerScoreStats.php`
- Delete: `app/Console/Commands/Concerns/SyncsPlayerScores.php`
- Delete: `app/Console/Commands/Concerns/SyncsPlayerScoreStats.php`
- Delete: `tests/Feature/Console/Commands/SyncCurrentSeasonPlayerScoresTest.php`
- Delete: `tests/Feature/Console/Commands/SyncLiveSeasonPlayerScoresTest.php`
- Delete: `tests/Feature/Console/Commands/SyncCurrentSeasonPlayerScoreStatsTest.php`
- Delete: `tests/Feature/Console/Commands/SyncLiveSeasonPlayerScoreStatsTest.php`
- Modify: `bootstrap/app.php`

**Interfaces:**
- Removes: `season:sync-player-scores`, `season:sync-live-player-scores`, `season:sync-player-score-stats`, `season:sync-live-player-score-stats` commands and their schedule entries. Replacement sync logic against the new schema is explicitly out of scope for this plan (a future, separate phase) — per the user, nothing here reaches production until that phase also ships.

- [ ] **Step 1: Delete the commands, concerns, and tests**

```bash
git rm app/Console/Commands/SyncCurrentSeasonPlayerScores.php app/Console/Commands/SyncLiveSeasonPlayerScores.php app/Console/Commands/SyncCurrentSeasonPlayerScoreStats.php app/Console/Commands/SyncLiveSeasonPlayerScoreStats.php
git rm app/Console/Commands/Concerns/SyncsPlayerScores.php app/Console/Commands/Concerns/SyncsPlayerScoreStats.php
git rm tests/Feature/Console/Commands/SyncCurrentSeasonPlayerScoresTest.php tests/Feature/Console/Commands/SyncLiveSeasonPlayerScoresTest.php tests/Feature/Console/Commands/SyncCurrentSeasonPlayerScoreStatsTest.php tests/Feature/Console/Commands/SyncLiveSeasonPlayerScoreStatsTest.php
```

- [ ] **Step 2: Remove their schedule entries from `bootstrap/app.php`**

Delete these four `$schedule->command(...)` blocks entirely (each including its trailing blank line, to avoid leaving double blank lines):

```php
        $schedule->command('season:sync-player-scores')
            ->everyFifteenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-live-player-scores')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-player-score-stats')
            ->everyFifteenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-live-player-score-stats')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test`
Expected: only `SyncCurrentSeasonManagerLineupsTest` should still be failing — Task 16's job.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: remove sync commands that wrote to the now-eliminated player_scores columns"
```

---

### Task 16: `SyncCurrentSeasonManagerLineups` — stop writing points/stats, resolve and write `fixture_id`

**Files:**
- Modify: `app/Console/Commands/SyncCurrentSeasonManagerLineups.php`
- Modify: `tests/Feature/Console/Commands/SyncCurrentSeasonManagerLineupsTest.php`

**Interfaces:**
- Produces: `ManagerLineupPlayer` rows now get `fixture_id` set (the player's team's fixture for that `week_number`, looked up the same way `AttachesRecentScores`/`PlayersController` already do it) instead of `points`/`stats` (columns no longer exist, per Task 5).

- [ ] **Step 1: Write the failing test — replaces the first test in the file**

Replace `test('syncs lineups for each season manager through the current week', ...)` with:

```php
test('syncs lineups for each season manager through the current week, resolving fixture_id', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $seasonManager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394771,
    ]);
    $player = Player::factory()->create(['fantasy_id' => 2759]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $player->team_id,
    ]);
    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamLineupRequest::class => MockResponse::make([
            'formation' => [
                'goalkeeper' => [
                    [
                        'playerMaster' => [
                            'id' => '2759',
                            'points' => 154,
                            'weekPoints' => 154,
                        ],
                    ],
                ],
                'defender' => [],
                'midfield' => [],
                'striker' => [],
                'tacticalFormation' => [3, 5, 2],
            ],
            'points' => 6,
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonManagerLineups::class)
        ->expectsOutput('1 manager lineups synchronized.')
        ->assertSuccessful();

    $lineup = ManagerLineup::query()->sole();
    $lineupPlayer = ManagerLineupPlayer::query()->sole();

    expect($lineup->season_manager_id)->toBe($seasonManager->id)
        ->and($lineup->week_number)->toBe(1)
        ->and($lineup->tactical_formation)->toBe([3, 5, 2])
        ->and($lineup->points)->toBe(6)
        ->and($lineupPlayer->player_id)->toBe($player->id)
        ->and($lineupPlayer->fixture_id)->toBe($fixture->id)
        ->and($lineupPlayer->position)->toBe(PlayerPosition::Goalkeeper);
});

test('leaves fixture_id null when the player\'s team has no fixture that week', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $seasonManager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394771,
    ]);
    Player::factory()->create(['fantasy_id' => 2759]);
    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamLineupRequest::class => MockResponse::make([
            'formation' => [
                'goalkeeper' => [
                    ['playerMaster' => ['id' => '2759', 'points' => 154]],
                ],
                'defender' => [],
                'midfield' => [],
                'striker' => [],
                'tacticalFormation' => [3, 5, 2],
            ],
            'points' => 0,
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonManagerLineups::class)
        ->expectsOutput('1 manager lineups synchronized.')
        ->assertSuccessful();

    expect(ManagerLineupPlayer::query()->sole()->fixture_id)->toBeNull();
});
```

Keep the existing `'stores null player lineup points when that week is not in lastStats'` test but rename it and drop its now-nonexistent `points`/`stats` assertions — since `lastStats` no longer maps to anything this command writes (that was already `points`/`stats`-specific, and per Task 15 that whole concept moved out of this command's scope). Actually: **delete that test entirely** — it tested behavior (`lastStats` parsing) that this command no longer performs at all (Step 3 below removes the `lastStats` lookup along with `points`/`stats` writing). Also update `'removes lineup players that are no longer in the fetched formation'` — no changes needed to its body since it doesn't touch `points`/`stats`/`fixture_id`, only re-verify it still passes after Step 3.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SyncCurrentSeasonManagerLineupsTest`
Expected: FAIL — `fixture_id` isn't written yet, and the deleted test's assertions on `->points`/`->stats` error since those properties no longer exist on `ManagerLineupPlayer`.

- [ ] **Step 3: Rewrite the command**

In `app/Console/Commands/SyncCurrentSeasonManagerLineups.php`, replace the `use` block's `App\Support\Arr` import — keep it if still needed elsewhere (it isn't, after this rewrite; remove `use Illuminate\Support\Arr;`). Add `use App\Models\Fixture;`.

Replace the `handle()` method's inner `DB::transaction(...)` closure body:

```php
                DB::transaction(function () use ($season, $seasonManager, $weekNumber, $formation): void {
                    $lineup = ManagerLineup::query()->updateOrCreate(
                        [
                            'season_manager_id' => $seasonManager->id,
                            'week_number' => $weekNumber,
                        ],
                        [
                            'tactical_formation' => $formation['tacticalFormation'] ?? [],
                            'points' => (int) ($lineupData['points'] ?? 0),
                        ],
                    );

                    $syncedPlayerIds = [];

                    foreach (PlayerPosition::cases() as $position) {
                        $players = $formation[$position->value] ?? [];

                        if (!is_array($players)) {
                            continue;
                        }

                        foreach ($players as $lineupPlayerData) {
                            $playerData = $lineupPlayerData['playerMaster'] ?? null;

                            if (!is_array($playerData)) {
                                continue;
                            }

                            $player = Player::query()
                                ->where('fantasy_id', (int) $playerData['id'])
                                ->first();

                            if ($player === null) {
                                continue;
                            }

                            $fixture = Fixture::query()
                                ->where('season_id', $season->id)
                                ->where('week_number', $weekNumber)
                                ->where(fn ($query) => $query
                                    ->where('team_local_id', $player->team_id)
                                    ->orWhere('team_guest_id', $player->team_id))
                                ->first();

                            ManagerLineupPlayer::query()->updateOrCreate(
                                [
                                    'manager_lineup_id' => $lineup->id,
                                    'player_id' => $player->id,
                                ],
                                [
                                    'fixture_id' => $fixture?->id,
                                    'position' => $position,
                                ],
                            );

                            $syncedPlayerIds[] = $player->id;
                        }
                    }

                    ManagerLineupPlayer::query()
                        ->where('manager_lineup_id', $lineup->id)
                        ->whereNotIn('player_id', $syncedPlayerIds)
                        ->delete();
                });
```

Note `$lineupData` (the outer `foreach`'s already-fetched response) is still captured by the closure the same way it was before — only the `use (...)` list changed (added `$season`, dropped nothing that was there — check the surrounding `foreach` still assigns `$lineupData`/`$formation` above this block, unchanged). `$this->subMinute`-style helpers aren't relevant here; the removed code is only the `$lastStats`/`$weekStats` lookup block and the `points`/`stats` keys in the `updateOrCreate` call.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncCurrentSeasonManagerLineupsTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions anywhere in the suite.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncCurrentSeasonManagerLineups.php tests/Feature/Console/Commands/SyncCurrentSeasonManagerLineupsTest.php
git commit -m "feat: resolve and store fixture_id instead of points/stats when syncing manager lineups"
```

---

### Task 17: Final verification

**Files:** none — verification only.

- [ ] **Step 1: Full backend test suite**

Run: `php artisan test`
Expected: PASS in full (aside from the 3 pre-existing unrelated Vite-manifest `FixturesControllerTest` failures already known from phase 3, if still present — not this plan's concern).

- [ ] **Step 2: Frontend type-check**

Run: `npm run build`
Expected: PASS, no type errors.

- [ ] **Step 3: Grep sweep for dangling references**

Run (via `Grep`, not shell) across `app/`, `resources/js/`, `tests/`: confirm zero remaining matches for `PlayerScore` (class or import) and zero remaining matches for `->points` / `->stats` called directly on a `ManagerLineupPlayer` instance (as opposed to via `->fixtureLineup?->fantasy_points`). Fix any stragglers found before considering this plan done.

- [ ] **Step 4: Manual browser check**

Per the `run` skill: walk through a fixture page (with and without synced lineup data), a player ficha, and a season-manager ficha, confirming points/stats/lineup_manager/DAZN all render correctly end to end.

No commit for this task — it's verification only. Report back with a summary of what was found (or confirm everything passed clean).
