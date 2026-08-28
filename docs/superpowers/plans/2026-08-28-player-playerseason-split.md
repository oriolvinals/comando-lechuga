# Player / PlayerSeason Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split `Player` into identity fields (`fantasy_id`, `nickname`, `status`, `image`, `team_id`) and a new per-season `PlayerSeason` model (`position`, `market_value`, `market_value_difference`, `points`, `average_points`), without changing the frontend contract or any existing controller JSON shape.

**Architecture:** New `player_seasons` table, one row per `(player_id, season_id)`. Controllers keep exposing `position`/`points`/`market_value`/`market_value_difference`/`average_points` on the serialized `Player` by attaching the current season's `PlayerSeason` row as computed, non-persisted attributes at query time — the same pattern already used in this codebase for `owner_manager` and `recent_scores`. `PlayerFactory` transparently forwards season-shaped overrides into an auto-created `PlayerSeason`, so almost no existing test call sites need to change.

**Tech Stack:** Laravel 12, Eloquent, Pest, SQLite (tests), Larastan level 7.

**Spec:** `docs/superpowers/specs/2026-08-28-player-playerseason-split-design.md`

## Global Constraints

- `players` keeps: `fantasy_id`, `nickname`, `status`, `image`, `team_id`. Everything else season-scoped moves to `player_seasons`.
- `player_seasons` unique on `(player_id, season_id)`.
- The Inertia/JSON shape of `Player` (as read by `resources/js/types/models.ts`'s `Player` interface) must not change — `position`, `points`, `market_value`, `market_value_difference`, `average_points` keep appearing exactly as today, just computed instead of stored.
- Run `vendor/bin/pint --parallel` is not required per-task (formatting), but every task must leave `php artisan test` green before committing.
- Every DB-writing command that currently sets `position`/`market_value`/`market_value_difference`/`points`/`average_points` directly on `Player` must be updated to write to `PlayerSeason` for `Season::current()` instead.

---

## Task 1: `player_seasons` migration (create, backfill, drop from `players`)

**Files:**
- Create: `database/migrations/2026_08_28_120000_create_player_seasons_table.php`

**Interfaces:**
- Produces: table `player_seasons` with columns `id`, `player_id` (FK → `players.id`, cascade delete), `season_id` (FK → `seasons.id`, cascade delete), `position` (string, not null), `market_value` (unsigned int, default 0), `market_value_difference` (int, default 0), `points` (unsigned int, default 0), `average_points` (decimal, default 0), unique on `(player_id, season_id)` named `player_seasons_unique`. Drops `position`, `market_value`, `market_value_difference`, `points`, `average_points` from `players`.

- [ ] **Step 1: Write the migration**

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
        Schema::create('player_seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->string('position')->nullable(false);
            $table->unsignedInteger('market_value')->nullable(false)->default(0);
            $table->integer('market_value_difference')->nullable(false)->default(0);
            $table->integer('points')->nullable(false)->default(0);
            $table->decimal('average_points')->nullable(false)->default(0);
            $table->unique(['player_id', 'season_id'], 'player_seasons_unique');
        });

        $currentSeasonId = DB::table('seasons')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->value('id');

        if ($currentSeasonId !== null) {
            DB::table('players')
                ->select('id', 'position', 'market_value', 'market_value_difference', 'points', 'average_points')
                ->orderBy('id')
                ->chunkById(200, function ($players) use ($currentSeasonId): void {
                    DB::table('player_seasons')->insert($players->map(fn ($player): array => [
                        'player_id' => $player->id,
                        'season_id' => $currentSeasonId,
                        'position' => $player->position,
                        'market_value' => $player->market_value,
                        'market_value_difference' => $player->market_value_difference,
                        'points' => $player->points,
                        'average_points' => $player->average_points,
                    ])->all());
                });
        }

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn(['position', 'market_value', 'market_value_difference', 'points', 'average_points']);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->string('position')->nullable(false)->default('');
            $table->unsignedInteger('market_value')->nullable(false)->default(0);
            $table->integer('market_value_difference')->nullable(false)->default(0);
            $table->integer('points')->nullable(false)->default(0);
            $table->decimal('average_points')->nullable(false)->default(0);
        });

        foreach (DB::table('player_seasons')->get() as $row) {
            DB::table('players')->where('id', $row->player_id)->update([
                'position' => $row->position,
                'market_value' => $row->market_value,
                'market_value_difference' => $row->market_value_difference,
                'points' => $row->points,
                'average_points' => $row->average_points,
            ]);
        }

        Schema::dropIfExists('player_seasons');
    }
};
```

- [ ] **Step 2: Run the migration in the test environment**

Run: `php artisan migrate --env=testing` (or simply run the suite in Step 3 — `RefreshDatabase` runs every migration before the first test).

Expected: no errors; `player_seasons` exists; `players` no longer has the five columns.

- [ ] **Step 3: Run the full suite to confirm nothing crashes yet**

Run: `php artisan test`
Expected: many FAILs (every place still reading/writing the moved columns on `Player` — that's expected at this point, later tasks fix them). Confirms the migration itself applies cleanly.

- [ ] **Step 4: Manually verify the backfill against the real dev database (not automated — see rationale below)**

This migration's backfill can't be exercised by a Pest test: `RefreshDatabase` runs every migration against an empty database before any test's `players` rows exist, so there is never "pre-migration data" for a test to observe. Verify by hand against your actual dev DB once, before deploying:

```bash
php artisan tinker --execute="echo App\Models\Player::query()->count();"
php artisan migrate
php artisan tinker --execute="echo DB::table('player_seasons')->count();"
php artisan tinker --execute="var_dump(DB::table('player_seasons')->first());"
```

Expected: the two counts match, and the sample row's `position`/`market_value`/`points`/`average_points` match a player you spot-check by eye in the admin DB / previous UI.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_28_120000_create_player_seasons_table.php
git commit -m "feat: add player_seasons table, backfill from players"
```

---

## Task 2: `PlayerSeason` model + factory

**Files:**
- Create: `app/Models/PlayerSeason.php`
- Create: `database/factories/PlayerSeasonFactory.php`
- Test: `tests/Feature/Models/PlayerSeasonTest.php`

**Interfaces:**
- Consumes: `player_seasons` table from Task 1.
- Produces: `PlayerSeason` model with `player(): BelongsTo<Player>`, `season(): BelongsTo<Season>`; casts `position` to `PlayerPosition`, `average_points` to `decimal:2`. `PlayerSeason::factory()` usable via `->for($player)->for($season)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;

test('casts its position enum and belongs to a player and a season', function (): void {
    $player = Player::factory()->create();
    $season = Season::factory()->create();

    $playerSeason = PlayerSeason::factory()->create([
        'player_id' => $player->id,
        'season_id' => $season->id,
        'position' => PlayerPosition::Striker,
    ]);

    expect($playerSeason->position)->toBe(PlayerPosition::Striker)
        ->and($playerSeason->player->id)->toBe($player->id)
        ->and($playerSeason->season->id)->toBe($season->id);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PlayerSeasonTest`
Expected: FAIL — class `App\Models\PlayerSeason` not found.

- [ ] **Step 3: Write the model**

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\PlayerSeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $player_id
 * @property-read int $season_id
 * @property-read PlayerPosition $position
 * @property-read int $market_value
 * @property-read int $market_value_difference
 * @property-read int $points
 * @property-read string $average_points
 */
#[UseFactory(PlayerSeasonFactory::class)]
#[Table(name: 'player_seasons', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['player_id', 'season_id', 'position', 'market_value', 'market_value_difference', 'points', 'average_points'])]
class PlayerSeason extends Model
{
    /** @use HasFactory<PlayerSeasonFactory> */
    use HasFactory;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'market_value' => 0,
        'market_value_difference' => 0,
        'points' => 0,
        'average_points' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'player_id' => 'int',
            'season_id' => 'int',
            'position' => PlayerPosition::class,
            'market_value' => 'int',
            'market_value_difference' => 'int',
            'points' => 'int',
            'average_points' => 'decimal:2',
        ];
    }
}
```

- [ ] **Step 4: Write the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerSeason>
 */
class PlayerSeasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'season_id' => Season::factory(),
            'position' => $this->faker->randomElement(PlayerPosition::cases()),
            'market_value' => $this->faker->numberBetween(0, 200000000),
            'market_value_difference' => $this->faker->numberBetween(-500000, 500000),
            'points' => $this->faker->numberBetween(0, 1000),
            'average_points' => $this->faker->randomFloat(2, 0, 100),
        ];
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PlayerSeasonTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Models/PlayerSeason.php database/factories/PlayerSeasonFactory.php tests/Feature/Models/PlayerSeasonTest.php
git commit -m "feat: add PlayerSeason model and factory"
```

---

## Task 3: Strip season fields from `Player`, update `PlayerFactory` to match

Both files must change together: as soon as `Player` loses the season columns, `PlayerFactory::definition()` would break every call site that passes `position`/`points`/`market_value`/`market_value_difference` unless the factory is updated in the same step to route those into an auto-created `PlayerSeason`. They're one task because neither half is independently testable.

**Files:**
- Modify: `app/Models/Player.php`
- Modify: `database/factories/PlayerFactory.php`
- Test: `tests/Feature/Models/PlayerTest.php` (existing, must stay green)

**Interfaces:**
- Produces: `Player::seasons(): HasMany<PlayerSeason>`. `Player` no longer has `position`/`market_value`/`market_value_difference`/`points`/`average_points` as real columns/casts/fillable, but keeps `@property` docblocks for them documented as computed (populated by Task 7's controller concern, and mirrored in-memory by this task's factory change). `Player::factory()->create([...])` keeps accepting `position`, `market_value`, `market_value_difference`, `points`, `average_points` exactly as before — they're routed to a `PlayerSeason` for the current season (creating one with today's date range if none exists yet), and mirrored back onto the returned `Player` instance so `$player->position` etc. work immediately without a re-fetch.

- [ ] **Step 1: Update the model**

Replace the whole file with:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read int $id
 * @property-read int $fantasy_id
 * @property-read string $nickname
 * @property-read PlayerStatus $status
 * @property-read string $image
 * @property-read int $team_id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property PlayerPosition|null $position Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $market_value Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $market_value_difference Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $points Computed at query time from the current season's PlayerSeason; not a database column.
 * @property string $average_points Computed at query time from the current season's PlayerSeason; not a database column.
 * @property array{id: int, name: string, logo: string}|null $owner_manager Computed at query time by PlayersController; not a database column.
 * @property array<int, int|null> $recent_scores Points for the last 3 played matches, oldest first, ordered by fixture date; null-padded at the end when fewer than 3 exist. Computed at query time by PlayersController; not a database column.
 * @property array<int, bool> $recent_scores_finished Per recent_scores slot, whether a real finished fixture exists there — false means the team hasn't played that many matches yet, never "not called up" (a finished fixture with no score is still true, with a null recent_scores value). Computed at query time alongside recent_scores; not a database column.
 * @property array<int, bool|null>|null $recent_scores_used Per recent_scores slot, whether the player was in that manager's lineup that week. Only set on the manager ficha (SeasonManagersController); null-padded like recent_scores, and entirely absent elsewhere.
 */
#[UseFactory(PlayerFactory::class)]
#[Table(name: 'players', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['fantasy_id', 'nickname', 'status', 'image', 'team_id'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<PlayerSeason, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(PlayerSeason::class);
    }

    /** @return HasMany<PlayerMarket, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(PlayerMarket::class);
    }

    /** @return HasMany<PlayerScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(PlayerScore::class);
    }

    /** @return HasMany<ManagerLineupPlayer, $this> */
    public function lineupPlayers(): HasMany
    {
        return $this->hasMany(ManagerLineupPlayer::class);
    }

    /** @return HasMany<ManagerPlayer, $this> */
    public function seasonManagerPlayers(): HasMany
    {
        return $this->hasMany(ManagerPlayer::class);
    }

    /** @return HasOne<MarketPlayer, $this> */
    public function marketPlayer(): HasOne
    {
        return $this->hasOne(MarketPlayer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        $data['image'] = $this->image ? asset('storage/'.$this->image) : '';

        return $data;
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'nickname' => '',
        'image' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'int',
            'nickname' => 'string',
            'status' => PlayerStatus::class,
            'image' => 'string',
            'team_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

- [ ] **Step 2: Run the existing model test to see it fail**

Run: `php artisan test --filter=PlayerTest`
Expected: FAIL — `$player->position` is null, since `PlayerFactory` still tries to pass `position` straight into `Player::create()`, which no longer has that column.

- [ ] **Step 3: Rewrite the factory**

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * Keys that belong to PlayerSeason, not Player — intercepted in create()
     * below so existing call sites like Player::factory()->create(['points' => 90])
     * keep working after the Player/PlayerSeason split.
     *
     * @var list<string>
     */
    private const array SEASON_KEYS = ['position', 'market_value', 'market_value_difference', 'points', 'average_points'];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999),
            'nickname' => $this->faker->name(),
            'status' => $this->faker->randomElement(PlayerStatus::cases()),
            'image' => '',
            'team_id' => Team::factory(),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Player>|Player
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        $seasonAttributes = array_intersect_key($attributes, array_flip(self::SEASON_KEYS));
        $playerAttributes = array_diff_key($attributes, $seasonAttributes);

        // Persist via make()+store()+callAfterCreating() — mirroring Factory::create()'s
        // own terminal branch — rather than calling parent::create($playerAttributes, $parent)
        // directly. Laravel's base create() re-enters create() (via state()->create()) whenever
        // $attributes is non-empty, and since state()/newInstance() construct a new PlayerFactory
        // instance, that re-entrant call still resolves to *this* override. Left uncorrected, the
        // PlayerSeason-routing logic below would then run twice per factory call — once inside the
        // re-entrant call and once here — inserting two PlayerSeason rows for the same player+season
        // and failing the player_seasons unique constraint. make() is not overridden here, so calling
        // it directly avoids the bounce back into this method.
        $made = $this->make($playerAttributes, $parent);

        /** @var Collection<int, Player> $players */
        $players = $made instanceof Model ? new Collection([$made]) : $made;

        $this->store($players);
        $this->callAfterCreating($players, $parent);

        $result = $made;

        $season = Season::query()
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->first() ?? Season::factory()->create([
                'start_date' => now()->subDay(),
                'end_date' => now()->addDay(),
            ]);

        foreach ($players as $player) {
            $playerSeason = PlayerSeason::factory()->for($player)->for($season)->create($seasonAttributes);

            $player->position = $playerSeason->position;
            $player->market_value = $playerSeason->market_value;
            $player->market_value_difference = $playerSeason->market_value_difference;
            $player->points = $playerSeason->points;
            $player->average_points = $playerSeason->average_points;
        }

        return $result;
    }
}
```

**Post-review correction:** the original draft of this step called `parent::create($playerAttributes, $parent)` instead of the `make()`+`store()`+`callAfterCreating()` sequence above. Task 3's implementer found and proved (with debug instrumentation) that this causes a `player_seasons` unique-constraint violation on every call that passes any non-season attribute — Laravel's base `Factory::create()` re-enters `create()` via `state($attributes)->create([], $parent)` whenever `$attributes` is non-empty, and since `state()` preserves the concrete `PlayerFactory` class, that re-entrant call dispatches back to this same override, running the `PlayerSeason`-creation loop twice. The code above is the corrected, verified version — already what's implemented and reviewed.

- [ ] **Step 4: Run the model test again to see it pass**

Run: `php artisan test --filter=PlayerTest`
Expected: PASS

- [ ] **Step 5: Run the full suite**

Run: `php artisan test`
Expected: still several FAILs in sync-command and controller tests (Tasks 4-7 fix those) — but no more failures caused by the factory itself refusing unknown keys or by `PlayerTest`.

- [ ] **Step 6: Commit**

```bash
git add app/Models/Player.php database/factories/PlayerFactory.php
git commit -m "refactor: split Player into identity fields + PlayerSeason"
```

---

## Task 4: `SyncCurrentSeasonPlayers` writes identity to `Player`, season data to `PlayerSeason`

**Files:**
- Modify: `app/Console/Commands/SyncCurrentSeasonPlayers.php`
- Test: `tests/Feature/Console/Commands/SyncCurrentSeasonPlayersTest.php`

**Interfaces:**
- Produces: unchanged console signature/output; now performs one `updateOrCreate` on `Player` (identity) and one `updateOrCreate` on `PlayerSeason` keyed by `(player_id, season_id)` per synced player.

- [ ] **Step 1: Update the failing assertion in the existing test**

In `tests/Feature/Console/Commands/SyncCurrentSeasonPlayersTest.php`, replace:

```php
    expect(Player::query()->count())->toBe(2)
        ->and($existingPlayer->refresh())
        ->nickname->toBe('Unai Simón')
        ->and($existingPlayer->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($existingPlayer->image)->toBe('images/player/68.png')
        ->and($existingPlayer->team_id)->toBe($team->id);
```

with:

```php
    expect(Player::query()->count())->toBe(2)
        ->and($existingPlayer->refresh())
        ->nickname->toBe('Unai Simón')
        ->and($existingPlayer->image)->toBe('images/player/68.png')
        ->and($existingPlayer->team_id)->toBe($team->id)
        ->and($existingPlayer->seasons()->where('season_id', $season->id)->sole()->position)->toBe(PlayerPosition::Goalkeeper);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyncCurrentSeasonPlayersTest`
Expected: FAIL — `$existingPlayer->seasons()` returns no rows for `$season->id` yet (the command still writes position onto `Player`, which no longer has that column, so this currently errors at the SQL level).

- [ ] **Step 3: Update the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-players')]
#[Description('Synchronize the current season players from La Liga Fantasy')]
class SyncCurrentSeasonPlayers extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $season = Season::current();
        $teams = $season->teams()->get()->keyBy('fantasy_id');
        $players = [];

        foreach ($connector->getPlayers()->throw()->json() as $playerData) {
            /** @var Team|null $team */
            $team = $teams->get((int)$playerData['teamId']);

            if ($team === null) {
                continue;
            }

            $players[] = [
                'fantasy_id' => (int)$playerData['id'],
                'nickname' => (string)$playerData['nickname'],
                'status' => PlayerStatus::from((string)$playerData['playerStatus']),
                'team_id' => $team->id,
                'position' => PlayerPosition::fromFantasyId((int)$playerData['positionId']),
                'market_value' => (int)$playerData['marketValue'],
                'points' => (int)$playerData['points'],
                'average_points' => (float) $playerData['averagePoints'],
            ];
        }

        $playerIds = DB::transaction(function () use ($players, $season): array {
            $playerIds = [];

            foreach ($players as $playerData) {
                $player = Player::query()->updateOrCreate(
                    ['fantasy_id' => $playerData['fantasy_id']],
                    [
                        'nickname' => $playerData['nickname'],
                        'status' => $playerData['status'],
                        'team_id' => $playerData['team_id'],
                    ],
                );

                PlayerSeason::query()->updateOrCreate(
                    ['player_id' => $player->id, 'season_id' => $season->id],
                    [
                        'position' => $playerData['position'],
                        'market_value' => $playerData['market_value'],
                        'points' => $playerData['points'],
                        'average_points' => $playerData['average_points'],
                    ],
                );

                $playerIds[] = $player->id;
            }

            return $playerIds;
        });

        $this->info(count($playerIds).' players synchronized.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SyncCurrentSeasonPlayersTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SyncCurrentSeasonPlayers.php tests/Feature/Console/Commands/SyncCurrentSeasonPlayersTest.php
git commit -m "feat: SyncCurrentSeasonPlayers writes season data to PlayerSeason"
```

---

## Task 5: `SyncCurrentSeasonPlayerMarkets` writes `market_value_difference` to `PlayerSeason`

**Files:**
- Modify: `app/Console/Commands/SyncCurrentSeasonPlayerMarkets.php`
- Test: `tests/Feature/Console/Commands/SyncCurrentSeasonPlayerMarketsTest.php`

- [ ] **Step 1: Update the failing assertion**

Replace:

```php
    expect(PlayerMarket::query()->where('player_id', $player->id)->count())->toBe(2)
        ->and($player->refresh()->market_value_difference)->toBe(30)
        ->and(PlayerMarket::query()->where('date', '2026-08-20')->sole()->value)->toBe(120);
```

with:

```php
    expect(PlayerMarket::query()->where('player_id', $player->id)->count())->toBe(2)
        ->and($player->seasons()->where('season_id', $season->id)->sole()->market_value_difference)->toBe(30)
        ->and(PlayerMarket::query()->where('date', '2026-08-20')->sole()->value)->toBe(120);
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SyncCurrentSeasonPlayerMarketsTest`
Expected: FAIL — no `PlayerSeason` row exists yet for this player/season (the factory-created `Player` from this test doesn't pass `market_value_difference`, so Task 3's factory already created a default `PlayerSeason` row — the failure here is that the command still tries `$player->update(['market_value_difference' => ...])` against a column that no longer exists).

- [ ] **Step 3: Update the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerSeason;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-player-markets')]
#[Description('Synchronize the current season player markets from La Liga Fantasy')]
class SyncCurrentSeasonPlayerMarkets extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $season = Season::current();
        $players = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->get();
        $playersSynchronized = 0;

        foreach ($players as $player) {
            $markets = [];

            foreach ($connector->getPlayerMarketValue($player->fantasy_id)->throw()->json() as $marketData) {
                $markets[] = [
                    'fantasy_id' => (int)$marketData['lfpId'],
                    'player_id' => $player->id,
                    'date' => CarbonImmutable::parse($marketData['date'])->format('Y-m-d'),
                    'value' => (int)$marketData['marketValue'],
                ];
            }

            if ($markets === []) {
                continue;
            }

            usort($markets, static fn (array $left, array $right): int => $left['date'] <=> $right['date']);

            $lastIndex = count($markets) - 1;
            $difference = $lastIndex > 0
                ? $markets[$lastIndex]['value'] - $markets[$lastIndex - 1]['value']
                : 0;

            DB::transaction(function () use ($player, $markets, $difference, $season): void {
                foreach ($markets as $marketData) {
                    PlayerMarket::query()->updateOrCreate(
                        [
                            'player_id' => $marketData['player_id'],
                            'date' => $marketData['date'],
                        ],
                        $marketData,
                    );
                }

                PlayerSeason::query()->updateOrCreate(
                    ['player_id' => $player->id, 'season_id' => $season->id],
                    ['market_value_difference' => $difference],
                );
            });

            $playersSynchronized++;
        }

        $this->info($playersSynchronized.' player markets synchronized.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SyncCurrentSeasonPlayerMarketsTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SyncCurrentSeasonPlayerMarkets.php tests/Feature/Console/Commands/SyncCurrentSeasonPlayerMarketsTest.php
git commit -m "feat: SyncCurrentSeasonPlayerMarkets writes market_value_difference to PlayerSeason"
```

---

## Task 6: `SyncsPlayerScoreStats` writes `points` to `PlayerSeason`

**Files:**
- Modify: `app/Console/Commands/Concerns/SyncsPlayerScoreStats.php`
- Modify: `app/Console/Commands/SyncCurrentSeasonPlayerScoreStats.php`
- Modify: `app/Console/Commands/SyncLiveSeasonPlayerScoreStats.php`
- Test: `tests/Feature/Console/Commands/SyncCurrentSeasonPlayerScoreStatsTest.php`
- Test: `tests/Feature/Console/Commands/SyncLiveSeasonPlayerScoreStatsTest.php`

**Interfaces:**
- Produces: `syncPlayerScoreStats(Player $player, Season $season, LaLigaFantasyConnector $connector): int` — adds a required `Season $season` parameter; both callers already compute `$season = Season::current()` locally.

- [ ] **Step 1: Update the failing assertions**

In `tests/Feature/Console/Commands/SyncCurrentSeasonPlayerScoreStatsTest.php`, replace every `$xxx->refresh()->points` with `$xxx->seasons()->where('season_id', $season->id)->sole()->points`. There are 3 occurrences (lines 64, 103 — read `$score->refresh()->points` there refers to `PlayerScore`, leave that one untouched — only the `Player` ones change —, 159-160):

```php
        ->and($player->refresh()->points)->toBe(47);
```
→
```php
        ->and($player->seasons()->where('season_id', $season->id)->sole()->points)->toBe(47);
```

```php
    expect($activePlayer->refresh()->points)->toBe(12)
        ->and($outOfLeaguePlayer->refresh()->points)->toBe(0);
```
→
```php
    expect($activePlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(12)
        ->and($outOfLeaguePlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(0);
```

(Leave `test('leaves existing points untouched when totalPoints is missing', ...)` — its only `->refresh()->points` call is on `$score` (`PlayerScore`), not `Player`; no change needed there.)

In `tests/Feature/Console/Commands/SyncLiveSeasonPlayerScoreStatsTest.php`, replace both occurrences:

```php
    expect($livePlayer->refresh()->points)->toBe(10)
        ->and($recentPlayer->refresh()->points)->toBe(20)
        ->and($oldPlayer->refresh()->points)->toBe(0)
        ->and($scheduledPlayer->refresh()->points)->toBe(0);
```
→
```php
    expect($livePlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(10)
        ->and($recentPlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(20)
        ->and($oldPlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(0)
        ->and($scheduledPlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(0);
```

```php
    expect($activePlayer->refresh()->points)->toBe(10)
        ->and($outOfLeaguePlayer->refresh()->points)->toBe(0);
```
→
```php
    expect($activePlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(10)
        ->and($outOfLeaguePlayer->seasons()->where('season_id', $season->id)->sole()->points)->toBe(0);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=SyncCurrentSeasonPlayerScoreStatsTest`
Run: `php artisan test --filter=SyncLiveSeasonPlayerScoreStatsTest`
Expected: FAIL — `syncPlayerScoreStats` still writes `points` directly onto `Player`.

- [ ] **Step 3: Update the trait**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Models\Season;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

trait SyncsPlayerScoreStats
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    private function syncPlayerScoreStats(Player $player, Season $season, LaLigaFantasyConnector $connector): int
    {
        $playerData = $connector
            ->getPlayer($player->fantasy_id)
            ->throw()
            ->json();

        if (isset($playerData['points'])) {
            PlayerSeason::query()->updateOrCreate(
                ['player_id' => $player->id, 'season_id' => $season->id],
                ['points' => (int) $playerData['points']],
            );
        }

        $playerStats = $playerData['playerStats'] ?? [];

        if (!is_array($playerStats)) {
            return 0;
        }

        return DB::transaction(fn (): int => $this->updateStats($player->id, $playerStats));
    }

    /**
     * @param  array<array-key, mixed>  $playerStats
     */
    private function updateStats(int $playerId, array $playerStats): int
    {
        $updated = 0;

        foreach ($playerStats as $scoreData) {
            if (!is_array($scoreData) || !isset($scoreData['weekNumber'])) {
                continue;
            }

            $stats = $scoreData['stats'] ?? [];

            if (!is_array($stats)) {
                continue;
            }

            $weekNumber = (int) $scoreData['weekNumber'];

            $updated += PlayerScore::query()
                ->where('player_id', $playerId)
                ->whereHas('fixture', fn ($query) => $query->where('week_number', $weekNumber))
                ->update([
                    ...isset($scoreData['totalPoints'])
                        ? ['points' => (int) $scoreData['totalPoints']]
                        : [],
                    'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
                    'ideal_formation' => (bool) ($scoreData['isInIdealFormation'] ?? false),
                ]);
        }

        return $updated;
    }
}
```

Note: since `PlayerSeason::updateOrCreate` requires the row's other not-null-without-default column (`position`) to already exist or be supplied, and every `Player` should already have a current-season `PlayerSeason` row by the time this runs (created either by `SyncCurrentSeasonPlayers` in production, or by the factory in tests), `updateOrCreate` here only ever *updates* in practice. If it ever needs to *create* (no prior row), Eloquent will fail on the missing `position`. That's acceptable: this command's job is score stats, not player identity — a player with no season row yet is a data-integrity problem from an earlier sync step, not something this command should paper over.

- [ ] **Step 4: Update both callers to pass `$season`**

In `app/Console/Commands/SyncCurrentSeasonPlayerScoreStats.php`, change:

```php
        foreach ($players as $player) {
            $scoresUpdated += $this->syncPlayerScoreStats($player, $connector);
        }
```

to:

```php
        foreach ($players as $player) {
            $scoresUpdated += $this->syncPlayerScoreStats($player, $season, $connector);
        }
```

In `app/Console/Commands/SyncLiveSeasonPlayerScoreStats.php`, change the same call site the same way (it already has `$season = Season::current();` earlier in `handle()`):

```php
        foreach ($players as $player) {
            $scoresUpdated += $this->syncPlayerScoreStats($player, $season, $connector);
        }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=SyncCurrentSeasonPlayerScoreStatsTest`
Run: `php artisan test --filter=SyncLiveSeasonPlayerScoreStatsTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/Concerns/SyncsPlayerScoreStats.php app/Console/Commands/SyncCurrentSeasonPlayerScoreStats.php app/Console/Commands/SyncLiveSeasonPlayerScoreStats.php tests/Feature/Console/Commands/SyncCurrentSeasonPlayerScoreStatsTest.php tests/Feature/Console/Commands/SyncLiveSeasonPlayerScoreStatsTest.php
git commit -m "feat: SyncsPlayerScoreStats writes points to PlayerSeason"
```

---

## Task 7: `PlayersController` attaches current-season data; `index()` filters/sorts via `player_seasons`

**Files:**
- Create: `app/Http/Controllers/Concerns/AttachesCurrentPlayerSeason.php`
- Modify: `app/Http/Controllers/PlayersController.php`

**Interfaces:**
- Produces: `attachCurrentSeason(Collection<int, Player>|LengthAwarePaginator<int, Player> $players, int $seasonId): void` — attaches `position`, `market_value`, `market_value_difference`, `points`, `average_points` onto each `Player` from its `PlayerSeason` row for `$seasonId`, mirroring the existing `attachOwnership`/`attachRecentScores` pattern.

- [ ] **Step 1: Write the concern**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Player;
use App\Models\PlayerSeason;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

trait AttachesCurrentPlayerSeason
{
    /**
     * @param  Collection<int, Player>|LengthAwarePaginator<int, Player>  $players
     */
    private function attachCurrentSeason(Collection|LengthAwarePaginator $players, int $seasonId): void
    {
        $entries = $players instanceof LengthAwarePaginator ? $players->getCollection() : $players;
        $playerIds = $entries->pluck('id')->all();

        $seasons = PlayerSeason::query()
            ->where('season_id', $seasonId)
            ->whereIn('player_id', $playerIds)
            ->get()
            ->keyBy('player_id');

        $entries->each(function (Player $player) use ($seasons): void {
            $season = $seasons->get($player->id);

            if ($season === null) {
                return;
            }

            $player->position = $season->position;
            $player->market_value = $season->market_value;
            $player->market_value_difference = $season->market_value_difference;
            $player->points = $season->points;
            $player->average_points = $season->average_points;
        });
    }
}
```

- [ ] **Step 2: Update `PlayersController`**

Add `use AttachesCurrentPlayerSeason;` alongside the existing `use AttachesRecentScores;`, and add the import `use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;`.

Replace the `index()` query with:

```php
        $players = Player::query()
            ->select('players.*')
            ->join('player_seasons', function ($join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'players.id')
                    ->where('player_seasons.season_id', $season->id);
            })
            ->with('team')
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->when($positions !== [], fn ($query) => $query->whereIn('player_seasons.position', $positions))
            ->when($teams !== [], fn ($query) => $query->whereIn('team_id', $teams))
            ->when($seasonManagers !== [], fn ($query) => $query->whereHas(
                'seasonManagerPlayers',
                fn ($query) => $query->whereIn('season_manager_id', $seasonManagers),
            ))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($search !== null, fn ($query) => $query->whereRaw(
                $this->foldedNicknameSql().' LIKE ?',
                ['%'.Str::lower(Str::ascii($search)).'%'],
            ))
            ->orderBy('player_seasons.'.$sort->column(), $direction->value)
            ->paginate(15)
            ->withQueryString();

        $this->attachOwnership($players, $season->id);
        $this->attachCurrentSeason($players, $season->id);
        $this->attachRecentScores($players->getCollection(), $season);
```

(Only the query itself and the new `attachCurrentSeason` call change; everything below in `index()` stays the same.)

In `show()`, right after `$player->load('team');` and `$season = Season::current();`, add:

```php
        $this->attachCurrentSeason(new Collection([$player]), $season->id);
```

- [ ] **Step 3: Run the full players controller test file**

Run: `php artisan test --filter=PlayersControllerTest`
Expected: PASS — every existing test keeps working unchanged, since `Player::factory()->create(['position' => ..., 'points' => ...])` already routes through Task 3's factory, and the controller now re-attaches the same data from `player_seasons`.

- [ ] **Step 4: Commit**

```bash
git add app/Http/Controllers/Concerns/AttachesCurrentPlayerSeason.php app/Http/Controllers/PlayersController.php
git commit -m "feat: PlayersController reads position/points/market_value from PlayerSeason"
```

---

## Task 8: Full-suite and static-analysis verification

**Files:** none (verification only).

- [ ] **Step 1: Run the whole test suite**

Run: `php artisan test`
Expected: all green. If anything outside the files touched above still fails, it's a straggler reference to the moved columns not caught by the greps done during planning (check especially any seeder, other controller, or Inertia response touching `Player`) — fix it following the same "read from `player_seasons`" pattern as Task 7, then re-run.

- [ ] **Step 2: Run static analysis and frontend type checks**

Run: `composer analyze`
Expected: no new Larastan errors (level 7) about the `Player`/`PlayerSeason` split, and `npm run types:check` passes — `resources/js/types/models.ts`'s `Player` interface didn't change, so this should be a no-op confirmation, not a fix.

- [ ] **Step 3: Format**

Run: `vendor/bin/pint --parallel`
Expected: no diffs, or trivial formatting fixes — commit those separately if any appear.

- [ ] **Step 4: Final commit (only if Step 3 produced changes)**

```bash
git add -A
git commit -m "style: pint formatting after Player/PlayerSeason split"
```
