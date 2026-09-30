# Previsión del valor de mañana (modo god) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

> **Branch:** implement this on its **own branch created from `main`** (e.g. `feature/value-forecast`), and only
> **after the radar branch (`feature/god-radar`) is merged into `main`**. Never start it on `feature/god-radar`. One
> commit per task; never merge into `main` without the user's explicit OK.

**Goal:** a daily "tomorrow's value" forecast for every league player (hybrid persistence + match-shock model), used as
the max bid's day 1, a recalibrated max-bid confidence, and one god-only «Mercado» section on the player ficha that
shows tomorrow + the max bid together.

**Architecture:**
- **Snapshots first**: `player_daily_signals` (status, next-match start probability, next rival difficulty, listed),
  written hourly by `season:snapshot-player-signals`, so history starts accumulating.
- **Model** (`app/Services/ValueForecast/`, pure given the DB rows): `ValueForecastFeatures` builds one
  `ValueForecastRow` per player-day from `player_markets`, `fixtures` and `fixture_lineups`; `ValueForecastFeatureVector`
  turns it into the 30 variables of the research (`backtest.js::feats`); `HybridRegression` accumulates ridge normal
  equations; `ValueForecastModel` predicts change, 80 % interval, P(up) and reasons; `ValueForecastWalkForward` walks the
  dates adding rows day by day (production = one day; backtests = many).
- **Persistence**: `value_forecasts` (one per player and target date) and `value_forecast_fits` (audit + input
  fingerprint), written by `season:forecast-values` every 15 min only when its inputs changed.
- **Max bid**: `MaxBidInputs::$dayOneForecast` re-anchors the projection (variant B); `season:backtest-max-bid` feeds the
  walk-forward forecast and gains a `--calibrate` mode that fits a confidence calibration (`MaxBidParameters`).
- **Ficha**: god-only `valueForecast` prop; `HqMaxBidCard` becomes `HqGodMarketSection`.

**Tech Stack:** Laravel 13 (PHP 8.5), Pest, Inertia v3 + React + TypeScript, Tailwind v4 (Comando HQ tokens),
lucide-react.

**Spec:** `docs/superpowers/specs/2026-09-30-value-forecast-design.md`. Research: `comando-lechuga-research/value-forecast/`
(`PLAN.md`, `informe.md`, `backtest-maxbid-day1.md`, `backtest.js`). Mock: `public/_prevision-valor.html`, variant A.

## Global Constraints

- **Commands**: PHP through Herd: `herd php artisan …`, `herd php vendor/bin/pint --dirty --format agent`,
  `herd php vendor/bin/phpstan analyse --memory-limit=2G`. Tests: `herd php artisan test --compact <path>`.
- **Frontend**: `npm run build`, `npm run types:check`, `npm run lint:check`. Never start `npm run dev`.
- **Never** call the external LaLiga Fantasy API and never read `.env` / `GOD_MODE_KEY`. Backtests on the local DB are
  read-only (SELECT); they must not write anything.
- **PHP style**: `declare(strict_types=1);` in every file under `app/` and `database/`; constructor promotion, explicit
  return types, curly braces everywhere, PHPDoc array shapes; `final` / `readonly` like sibling services.
- **AGENTS.md**: string columns that don't store an enum are non-nullable with default `''`
  (`value_forecast_fits.inputs_hash`). `player_daily_signals.status` stores the `PlayerStatus` enum.
- **No new base folders or dependencies.** The only new folder is `app/Services/ValueForecast/` (a sub-folder of an
  existing one, like `app/Services/Prizes/`).
- **Model constants** (verbatim from the spec): floor −3,46 %/day (`-0.0346`); ridge λ = `1e-4` added as `λ·n` to the
  diagonal except the intercept; target `clamp(y − p0, −0.1, 0.1)`; residual window 35 days (`T−34 … T`), two groups
  (team played yesterday / not; empty group → "not"); quantile `sorted[floor(p·(n−1))]` at 0.1 / 0.9; stable band ±0.5 %;
  rows from `season.start_date + 13 days`; at least 100 training rows; tramos `nomin / ≤2 / 3–5 / 6–9 / 10+`.
- **Status is informational only**: injured / suspended / doubtful never change the forecast or its interval.
- **PRIVACY (hard rule):** forecasts, fits, daily signals, `day_one_forecast` and the calibration never appear in any
  `/api/*` response or in `resources/docs/api-docs.md`. Nothing under `app/Http/Controllers/Api` or
  `app/Http/Resources` may read the new tables.
- **UI**: every element with `onClick` has `cursor-pointer`; no footnotes or explanatory small print; value changes
  styled like the rest of the app (`HqMarketValueDifference`, lime up / red down); 390 px without horizontal scroll;
  functional text ≥ 11 px; the god frame is the `hq-god-frame` utility with no "GOD MODE" text.
- **Commits**: one per task, message in English, ending with the attribution line
  `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.

## Review Focus

- **The stored forecast is from an older market day** (the scheduler hasn't run since today's values were published).
  The ficha must show no forecast and the max bid must fall back to its own day 1 — never mix yesterday's forecast with
  today's value. Pinned in Task 9 (`gatherInputs` ignores another `reference_date`) and Task 14 (`valueForecast` null).
- **A player with fewer than four market days, a missing day, or a 0 value** (new signing, sync gap) must simply get no
  row — no division by zero, no crash of the whole run. Pinned in Task 5.
- **A finished lineup whose fantasy points are still pending (`null`)** counts as a played match with 0 points (as the
  research did), not as "no match". Pinned in Task 5.
- **The scheduled command re-running with unchanged inputs** must not rewrite anything, and a match finishing later the
  same day must trigger a rewrite. Pinned in Task 8.
- **Early season / tiny history** (fewer than 100 training rows): no forecast, the command succeeds and says so, and the
  max bid behaves exactly as before. Pinned in Task 7 and Task 8.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_30_200000_create_player_daily_signals_table.php`, `app/Models/PlayerDailySignal.php`, `database/factories/PlayerDailySignalFactory.php` (create) | Daily snapshot table, model, factory. |
| `app/Console/Commands/SnapshotPlayerSignals.php` (create) | `season:snapshot-player-signals`, hourly. |
| `database/migrations/2026_09_30_200100_create_value_forecasts_table.php`, `…_200200_create_value_forecast_fits_table.php`, `app/Models/ValueForecast.php`, `app/Models/ValueForecastFit.php`, factories (create) | Forecast storage. |
| `app/Services/ValueForecast/ValueForecastParameters.php` (create) | Model constants. |
| `app/Services/ValueForecast/HybridRegression.php` (create) | Incremental ridge normal equations + solve. |
| `app/Services/ValueForecast/ValueForecastRow.php`, `ValueForecastFeatureVector.php` (create) | One player-day and its 30 variables. |
| `app/Services/ValueForecast/ValueForecastFeatures.php` (create) | DB → rows (few queries). |
| `app/Services/ValueForecast/ValueForecastModel.php`, `ValueForecastPrediction.php` (create) | Predict, interval, P(up), reasons. |
| `app/Services/ValueForecast/ValueForecastWalkForward.php`, `ValueForecastDay.php` (create) | Day-by-day fitting. |
| `app/Console/Commands/BacktestValueForecast.php` (create) | `season:backtest-value-forecast`. |
| `app/Services/ValueForecast/ValueForecastFingerprint.php`, `ValueForecastWriter.php` (create), `app/Console/Commands/ForecastValues.php` (create), `bootstrap/app.php` (modify) | Scheduled writing. |
| `app/Services/MaxBidInputs.php`, `MaxBidCalculator.php`, `MaxBidEstimate.php`, `MaxBidParameters.php` (modify) | Day 1 = forecast; calibration. |
| `app/Console/Commands/BacktestMaxBid.php` (modify) | Walk-forward day 1, ideal-bid error, `--calibrate`. |
| `app/Services/ValueForecast/ValueForecastPresenter.php` (create), `app/Http/Controllers/PlayersController.php` (modify) | God-only `valueForecast` prop. |
| `resources/js/types/models.ts`, `resources/js/lib/rival-difficulty.ts` (modify) | Types; ease → 0–10. |
| `resources/js/components/hq-max-bid-card.tsx` → `hq-god-market-section.tsx` (git mv + modify), `resources/js/pages/players/show.tsx` (modify) | The «Mercado» section. |
| `tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`, `ApiDocsDriftTest.php` (modify) | Privacy. |
| Optional: `app/Services/ComparedPlayers.php`, `app/Http/Controllers/PlayerComparisonController.php`, `resources/js/components/compare/derive.ts` (modify) | Verdict evidence «Mañana». |
| Tests | `tests/Feature/Console/Commands/SnapshotPlayerSignalsTest.php`, `tests/Feature/Models/ValueForecastTest.php`, `tests/Unit/Services/HybridRegressionTest.php`, `tests/Unit/Services/ValueForecastFeatureVectorTest.php`, `tests/Feature/Services/ValueForecastFeaturesTest.php`, `tests/Unit/Services/ValueForecastModelTest.php`, `tests/Feature/Services/ValueForecastWalkForwardTest.php`, `tests/Feature/Console/Commands/BacktestValueForecastTest.php`, `tests/Feature/Console/Commands/ForecastValuesTest.php`, `tests/Unit/Services/MaxBidFormulaTest.php`, `tests/Feature/Services/MaxBidCalculatorTest.php`, `tests/Feature/Console/Commands/BacktestMaxBidTest.php`, `tests/Unit/Services/MaxBidParametersTest.php`, `tests/Feature/Http/Controllers/PlayersControllerTest.php` |

Shared test helpers live in `tests/Pest.php` (under "Functions") so any single test file can run on its own:
`forecastRow()` (added in Task 4) and `forecastPlayer()` (added in Task 5, with `use` lines for `App\Enums\PlayerStatus`,
`App\Models\Player`, `App\Models\PlayerMarket`, `App\Models\Season`, `App\Models\Team`, `Carbon\CarbonImmutable`):

```php
/**
 * A season player with one market value per day ending on `$lastDate`.
 *
 * @param  list<int>  $values  oldest first
 * @param  array<string, mixed>  $attributes
 */
function forecastPlayer(Season $season, array $values, string $lastDate, array $attributes = []): Player
{
    $team = isset($attributes['team_id']) ? Team::query()->findOrFail($attributes['team_id']) : Team::factory()->create();
    $season->teams()->syncWithoutDetaching([$team->id]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, ...$attributes]);
    $last = CarbonImmutable::parse($lastDate);

    foreach (array_values($values) as $index => $value) {
        PlayerMarket::factory()->create([
            'player_id' => $player->id,
            'date' => $last->subDays(count($values) - 1 - $index)->toDateString(),
            'value' => $value,
        ]);
    }

    return $player;
}
```

---

### Task 1: Daily player signals (snapshots)

**Files:**
- Create: `database/migrations/2026_09_30_200000_create_player_daily_signals_table.php`
- Create: `app/Models/PlayerDailySignal.php`, `database/factories/PlayerDailySignalFactory.php`
- Create: `app/Console/Commands/SnapshotPlayerSignals.php`
- Modify: `bootstrap/app.php` (schedule)
- Test: `tests/Feature/Console/Commands/SnapshotPlayerSignalsTest.php`

**Interfaces:**
- Consumes: `StartProbabilities::nextFixtures(Season, list<int>): array<int, Fixture>`,
  `StartProbabilities::forPlayersNextFixture(Collection<Player>, Season): array<int, PlayerNextStart>`,
  `MatchDifficulty::forMany(list<array{0: Fixture, 1: int, 2: DifficultyVariant}>): list<?MatchDifficultyResult>`.
- Produces: table `player_daily_signals`, model `App\Models\PlayerDailySignal`, command
  `season:snapshot-player-signals`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Console\Commands\SnapshotPlayerSignals;
use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerDailySignal;
use App\Models\Season;
use App\Models\Team;
use App\Services\MatchDifficulty;

beforeEach(function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $this->team = Team::factory()->create();
    $this->rival = Team::factory()->create();
    $this->season->teams()->attach([$this->team->id, $this->rival->id]);
    $this->fixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'team_local_id' => $this->team->id,
        'team_guest_id' => $this->rival->id,
        'date' => '2026-10-04 19:00:00',
        'state' => FixtureState::Scheduled,
    ]);
});

test('stores today\'s status, start probability, next rival difficulty and listing of each league player', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::Doubtful, 'position' => PlayerPosition::Striker]);
    FixtureLineupProbability::factory()->create(['player_id' => $player->id, 'fixture_id' => $this->fixture->id, 'probability' => 70, 'predicted_starter' => true]);
    MarketPlayer::factory()->create(['player_id' => $player->id]);

    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    $signal = PlayerDailySignal::query()->sole();
    $expectedDifficulty = app(MatchDifficulty::class)->forMany([[$this->fixture, $this->team->id, DifficultyVariant::Attack]])[0]?->difficulty;

    expect($signal->player_id)->toBe($player->id)
        ->and($signal->season_id)->toBe($this->season->id)
        ->and($signal->date->toDateString())->toBe('2026-09-30')
        ->and($signal->status)->toBe(PlayerStatus::Doubtful)
        ->and($signal->next_fixture_id)->toBe($this->fixture->id)
        ->and($signal->start_probability)->toBe(70)
        ->and($signal->predicted_starter)->toBeTrue()
        ->and($signal->confirmed_starter)->toBeNull()
        ->and($signal->next_difficulty)->toBe($expectedDifficulty)
        ->and($signal->listed)->toBeTrue();
});

test('a second run the same day overwrites the row and a new day adds one', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::Ok]);

    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();
    $player->update(['status' => PlayerStatus::Injured]);
    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    expect(PlayerDailySignal::query()->count())->toBe(1)
        ->and(PlayerDailySignal::query()->sole()->status)->toBe(PlayerStatus::Injured);

    $this->travelTo('2026-10-01 09:00:00');
    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    expect(PlayerDailySignal::query()->count())->toBe(2);
});

test('skips out-of-league players and players of other teams, and works without a next match', function (): void {
    $this->fixture->delete();
    $kept = Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::Ok]);
    Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::OutOfLeague]);
    Player::factory()->create(['team_id' => Team::factory()->create()->id, 'status' => PlayerStatus::Ok]);

    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    $signal = PlayerDailySignal::query()->sole();
    expect($signal->player_id)->toBe($kept->id)
        ->and($signal->next_fixture_id)->toBeNull()
        ->and($signal->start_probability)->toBeNull()
        ->and($signal->next_difficulty)->toBeNull()
        ->and($signal->listed)->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/SnapshotPlayerSignalsTest.php`
Expected: FAIL — `Class "App\Console\Commands\SnapshotPlayerSignals" not found`.

- [ ] **Step 3: Migration, model, factory**

`database/migrations/2026_09_30_200000_create_player_daily_signals_table.php`:

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
        Schema::create('player_daily_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status');
            $table->foreignId('next_fixture_id')->nullable()->constrained('fixtures')->nullOnDelete();
            $table->unsignedTinyInteger('start_probability')->nullable();
            $table->boolean('predicted_starter')->default(false);
            $table->boolean('confirmed_starter')->nullable();
            $table->double('next_difficulty')->nullable();
            $table->boolean('listed')->default(false);
            $table->timestamps();

            $table->unique(['player_id', 'date']);
            $table->index(['season_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_daily_signals');
    }
};
```

`app/Models/PlayerDailySignal.php` (same attribute style as `FixtureLineupProbability`):

```php
<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PlayerDailySignalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's signals on one day (the last run of that day wins): status,
 * FútbolFantasy's start probability for his next match, that match's 0–10
 * difficulty and whether he is listed in the league market. Kept to measure
 * later whether they move tomorrow's value (value forecast spec §2). God
 * mode only: never exposed by the public API.
 *
 * @property-read int $id
 * @property-read int $season_id
 * @property-read int $player_id
 * @property-read CarbonImmutable $date
 * @property-read PlayerStatus $status
 * @property-read int|null $next_fixture_id
 * @property-read int|null $start_probability 0–100
 * @property-read bool $predicted_starter
 * @property-read bool|null $confirmed_starter
 * @property-read float|null $next_difficulty 0–10, 10 = hardest
 * @property-read bool $listed
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(PlayerDailySignalFactory::class)]
#[Table(name: 'player_daily_signals', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['season_id', 'player_id', 'date', 'status', 'next_fixture_id', 'start_probability', 'predicted_starter', 'confirmed_starter', 'next_difficulty', 'listed'])]
class PlayerDailySignal extends Model
{
    /** @use HasFactory<PlayerDailySignalFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'predicted_starter' => false,
        'listed' => false,
    ];

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_id' => 'int',
            'player_id' => 'int',
            'date' => 'immutable_date',
            'status' => PlayerStatus::class,
            'next_fixture_id' => 'int',
            'start_probability' => 'int',
            'predicted_starter' => 'bool',
            'confirmed_starter' => 'bool',
            'next_difficulty' => 'float',
            'listed' => 'bool',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

`database/factories/PlayerDailySignalFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerDailySignal;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerDailySignal>
 */
class PlayerDailySignalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'player_id' => Player::factory(),
            'date' => now()->toDateString(),
            'status' => PlayerStatus::Ok,
            'next_fixture_id' => null,
            'start_probability' => null,
            'predicted_starter' => false,
            'confirmed_starter' => null,
            'next_difficulty' => null,
            'listed' => false,
        ];
    }
}
```

- [ ] **Step 4: The command**

`app/Console/Commands/SnapshotPlayerSignals.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DifficultyVariant;
use App\Enums\PlayerStatus;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerDailySignal;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Services\MatchDifficulty;
use App\Services\StartProbabilities;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:snapshot-player-signals')]
#[Description('Store today\'s status, next-match start probability, next rival difficulty and market listing of every league player')]
class SnapshotPlayerSignals extends Command
{
    public function handle(StartProbabilities $startProbabilities, MatchDifficulty $matchDifficulty): int
    {
        $season = Season::current();
        $players = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->with('team')
            ->get();

        if ($players->isEmpty()) {
            $this->info('No hay jugadores que guardar.');

            return self::SUCCESS;
        }

        $positions = PlayerSeason::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $players->modelKeys())
            ->get(['player_id', 'position'])
            ->mapWithKeys(fn (PlayerSeason $playerSeason): array => [$playerSeason->player_id => $playerSeason->position]);
        $nextByTeam = $startProbabilities->nextFixtures($season, array_values(array_unique($players->pluck('team_id')->all())));
        $starts = $startProbabilities->forPlayersNextFixture($players, $season);

        $items = [];
        $itemPlayerIds = [];

        foreach ($players as $player) {
            $fixture = $nextByTeam[$player->team_id] ?? null;

            if ($fixture !== null) {
                $items[] = [$fixture, $player->team_id, DifficultyVariant::forPosition($positions[$player->id] ?? null)];
                $itemPlayerIds[] = $player->id;
            }
        }

        $difficulties = $items === [] ? [] : array_combine($itemPlayerIds, $matchDifficulty->forMany($items));
        $listed = MarketPlayer::query()->whereIn('player_id', $players->modelKeys())->pluck('player_id')->flip();
        $today = now()->toDateString();
        $now = now();

        $rows = $players->map(fn (Player $player): array => [
            'season_id' => $season->id,
            'player_id' => $player->id,
            'date' => $today,
            'status' => $player->status->value,
            'next_fixture_id' => ($nextByTeam[$player->team_id] ?? null)?->id,
            'start_probability' => $starts[$player->id]['probability'] ?? null,
            'predicted_starter' => $starts[$player->id]['predicted_starter'] ?? false,
            'confirmed_starter' => $starts[$player->id]['confirmed_starter'] ?? null,
            'next_difficulty' => ($difficulties[$player->id] ?? null)?->difficulty,
            'listed' => $listed->has($player->id),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        PlayerDailySignal::query()->upsert(
            $rows,
            ['player_id', 'date'],
            ['season_id', 'status', 'next_fixture_id', 'start_probability', 'predicted_starter', 'confirmed_starter', 'next_difficulty', 'listed', 'updated_at'],
        );

        $this->info(count($rows).' jugadores guardados para el '.$today.'.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Schedule it** — in `bootstrap/app.php`, inside `withSchedule`, after `season:sync-start-probabilities`:

```php
        $schedule->command('season:snapshot-player-signals')
            ->hourly()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 6: Run the tests**

Run: `herd php artisan migrate --no-interaction` (local), then
`herd php artisan test --compact tests/Feature/Console/Commands/SnapshotPlayerSignalsTest.php`
Expected: PASS (3 tests). `herd php artisan schedule:list` lists `season:snapshot-player-signals` hourly.

- [ ] **Step 7: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add database/migrations/2026_09_30_200000_create_player_daily_signals_table.php app/Models/PlayerDailySignal.php database/factories/PlayerDailySignalFactory.php app/Console/Commands/SnapshotPlayerSignals.php bootstrap/app.php tests/Feature/Console/Commands/SnapshotPlayerSignalsTest.php
git commit -m "feat: snapshot each player's daily status and start signals"
```

---

### Task 2: Forecast tables and models

**Files:**
- Create: `database/migrations/2026_09_30_200100_create_value_forecasts_table.php`,
  `database/migrations/2026_09_30_200200_create_value_forecast_fits_table.php`
- Create: `app/Models/ValueForecast.php`, `app/Models/ValueForecastFit.php`,
  `database/factories/ValueForecastFactory.php`, `database/factories/ValueForecastFitFactory.php`
- Test: `tests/Feature/Models/ValueForecastTest.php`

**Interfaces:**
- Produces: `ValueForecast` (`season_id, player_id, reference_date, target_date, value, predicted_value, low, high,
  change_pct` (percent, e.g. `5.2`), `up_probability` (0–1), `reasons` (array of
  `{kind: string, label: string, impact_pct: float}`)); `ValueForecastFit` (`season_id, reference_date, inputs_hash,
  coefficients` list<float>, `quantiles` array, `metrics` array).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Player;
use App\Models\Season;
use App\Models\ValueForecast;
use App\Models\ValueForecastFit;
use Illuminate\Database\UniqueConstraintViolationException;

test('a forecast casts its dates, amounts and reasons', function (): void {
    $forecast = ValueForecast::factory()->create([
        'reference_date' => '2026-09-29',
        'target_date' => '2026-09-30',
        'value' => 24_190_000,
        'predicted_value' => 25_443_846,
        'change_pct' => 5.2,
        'up_probability' => 0.99,
        'reasons' => [['kind' => 'inertia', 'label' => 'Inercia: cambio de hoy', 'impact_pct' => 5.65]],
    ])->fresh();

    expect($forecast->reference_date->toDateString())->toBe('2026-09-29')
        ->and($forecast->target_date->toDateString())->toBe('2026-09-30')
        ->and($forecast->predicted_value)->toBe(25_443_846)
        ->and($forecast->change_pct)->toBe(5.2)
        ->and($forecast->up_probability)->toBe(0.99)
        ->and($forecast->reasons[0]['kind'])->toBe('inertia');
});

test('one forecast per season, player and target date', function (): void {
    $season = Season::factory()->create();
    $player = Player::factory()->create();
    ValueForecast::factory()->create(['season_id' => $season->id, 'player_id' => $player->id, 'target_date' => '2026-09-30']);

    expect(fn () => ValueForecast::factory()->create(['season_id' => $season->id, 'player_id' => $player->id, 'target_date' => '2026-09-30']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a fit keeps its hash as an empty string by default and casts its json', function (): void {
    $fit = ValueForecastFit::factory()->create(['coefficients' => [0.1, -0.2], 'inputs_hash' => ''])->fresh();

    expect($fit->inputs_hash)->toBe('')
        ->and($fit->coefficients)->toBe([0.1, -0.2]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Models/ValueForecastTest.php`
Expected: FAIL — `Class "App\Models\ValueForecast" not found`.

- [ ] **Step 3: Migrations**

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
        Schema::create('value_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');
            $table->date('target_date');
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('predicted_value');
            $table->unsignedBigInteger('low');
            $table->unsignedBigInteger('high');
            $table->double('change_pct');
            $table->double('up_probability');
            $table->json('reasons');
            $table->timestamps();

            $table->unique(['season_id', 'player_id', 'target_date']);
            $table->index(['player_id', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_forecasts');
    }
};
```

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
        Schema::create('value_forecast_fits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');
            $table->string('inputs_hash')->default('');
            $table->json('coefficients');
            $table->json('quantiles');
            $table->json('metrics');
            $table->timestamps();

            $table->unique(['season_id', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_forecast_fits');
    }
};
```

- [ ] **Step 4: Models and factories**

`app/Models/ValueForecast.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ValueForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value LaLiga Fantasy is expected to publish for a player on
 * `target_date`, forecast with what was known at the end of
 * `reference_date` (value forecast spec §1). Written by
 * season:forecast-values. God mode only: never exposed by the public API.
 *
 * @property-read int $id
 * @property-read int $season_id
 * @property-read int $player_id
 * @property-read CarbonImmutable $reference_date
 * @property-read CarbonImmutable $target_date
 * @property-read int $value The value on `reference_date`
 * @property-read int $predicted_value
 * @property-read int $low 80 % interval, low end
 * @property-read int $high 80 % interval, high end
 * @property-read float $change_pct Forecast change in percent (5.2 = +5,2 %)
 * @property-read float $up_probability 0–1
 * @property-read list<array{kind: string, label: string, impact_pct: float}> $reasons
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(ValueForecastFactory::class)]
#[Table(name: 'value_forecasts', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['season_id', 'player_id', 'reference_date', 'target_date', 'value', 'predicted_value', 'low', 'high', 'change_pct', 'up_probability', 'reasons'])]
class ValueForecast extends Model
{
    /** @use HasFactory<ValueForecastFactory> */
    use HasFactory;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_id' => 'int',
            'player_id' => 'int',
            'reference_date' => 'immutable_date',
            'target_date' => 'immutable_date',
            'value' => 'int',
            'predicted_value' => 'int',
            'low' => 'int',
            'high' => 'int',
            'change_pct' => 'float',
            'up_probability' => 'float',
            'reasons' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

`app/Models/ValueForecastFit.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ValueForecastFitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The value forecast model as fitted for one reference date: its
 * coefficients, residual quantiles and size, plus the fingerprint of the
 * inputs it was fitted on (season:forecast-values skips a run whose
 * fingerprint didn't change). God mode only.
 *
 * @property-read int $id
 * @property-read int $season_id
 * @property-read CarbonImmutable $reference_date
 * @property-read string $inputs_hash
 * @property-read list<float> $coefficients
 * @property-read array<string, mixed> $quantiles
 * @property-read array<string, mixed> $metrics
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(ValueForecastFitFactory::class)]
#[Table(name: 'value_forecast_fits', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['season_id', 'reference_date', 'inputs_hash', 'coefficients', 'quantiles', 'metrics'])]
class ValueForecastFit extends Model
{
    /** @use HasFactory<ValueForecastFitFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'inputs_hash' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_id' => 'int',
            'reference_date' => 'immutable_date',
            'coefficients' => 'array',
            'quantiles' => 'array',
            'metrics' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

Factories:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\Season;
use App\Models\ValueForecast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValueForecast>
 */
class ValueForecastFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'player_id' => Player::factory(),
            'reference_date' => now()->toDateString(),
            'target_date' => now()->addDay()->toDateString(),
            'value' => 10_000_000,
            'predicted_value' => 10_100_000,
            'low' => 10_000_000,
            'high' => 10_200_000,
            'change_pct' => 1.0,
            'up_probability' => 0.9,
            'reasons' => [],
        ];
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\ValueForecastFit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValueForecastFit>
 */
class ValueForecastFitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'reference_date' => now()->toDateString(),
            'inputs_hash' => '',
            'coefficients' => [],
            'quantiles' => [],
            'metrics' => [],
        ];
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Models/ValueForecastTest.php`
Expected: PASS (3 tests).

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add database/migrations/2026_09_30_200100_create_value_forecasts_table.php database/migrations/2026_09_30_200200_create_value_forecast_fits_table.php app/Models/ValueForecast.php app/Models/ValueForecastFit.php database/factories/ValueForecastFactory.php database/factories/ValueForecastFitFactory.php tests/Feature/Models/ValueForecastTest.php
git commit -m "feat: add value forecast and fit tables"
```

---

### Task 3: Parameters and the incremental ridge regression

**Files:**
- Create: `app/Services/ValueForecast/ValueForecastParameters.php`, `app/Services/ValueForecast/HybridRegression.php`
- Test: `tests/Unit/Services/HybridRegressionTest.php`

**Interfaces:**
- Produces: `ValueForecastParameters` (readonly; properties below);
  `HybridRegression::__construct(int $features, float $lambda)`, `add(list<float> $x, float $y): void`,
  `count(): int`, `solve(): list<float>` (throws `RuntimeException` when singular).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\ValueForecast\HybridRegression;

test('recovers exact coefficients without ridge', function (): void {
    $regression = new HybridRegression(2, 0.0);

    foreach ([-2.0, -1.0, 0.0, 1.0, 3.0] as $x) {
        $regression->add([1.0, $x], 2.0 + 3.0 * $x);
    }

    [$intercept, $slope] = $regression->solve();

    expect($regression->count())->toBe(5)
        ->and($intercept)->toEqualWithDelta(2.0, 1e-9)
        ->and($slope)->toEqualWithDelta(3.0, 1e-9);
});

test('ridge adds lambda times the row count to every diagonal term but the intercept', function (): void {
    // XᵀX = [[2, 0], [0, 2]], Xᵀy = [0, 2]; λ·n = 0,5·2 = 1 → slope 2 / 3, intercept untouched at 0.
    $regression = new HybridRegression(2, 0.5);
    $regression->add([1.0, -1.0], -1.0);
    $regression->add([1.0, 1.0], 1.0);

    [$intercept, $slope] = $regression->solve();

    expect($intercept)->toEqualWithDelta(0.0, 1e-12)
        ->and($slope)->toEqualWithDelta(2 / 3, 1e-12);
});

test('an all-zero column is solvable with ridge and gets a zero coefficient', function (): void {
    $regression = new HybridRegression(3, 1e-4);

    foreach ([1.0, 2.0, 3.0] as $x) {
        $regression->add([1.0, $x, 0.0], $x);
    }

    expect($regression->solve()[2])->toBe(0.0);
});

test('fails on a wrong vector size and on an empty fit', function (): void {
    $regression = new HybridRegression(2, 1e-4);

    expect(fn () => $regression->add([1.0], 1.0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $regression->solve())->toThrow(RuntimeException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Unit/Services/HybridRegressionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/ValueForecast/ValueForecastParameters.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

/**
 * The constants of the hybrid "persistence + match shock" value forecast
 * (spec docs/superpowers/specs/2026-09-30-value-forecast-design.md §1).
 * The defaults are the backtested model of comando-lechuga-research/value-forecast.
 */
final readonly class ValueForecastParameters
{
    public function __construct(
        /** Lowest daily change LaLiga Fantasy ever publishes (−3,46 %). */
        public float $floor = -0.0346,
        /** Ridge strength, added as λ·n to every diagonal term but the intercept. */
        public float $lambda = 1e-4,
        /** The fitted target `y − p0` is clipped to ±this. */
        public float $targetClip = 0.1,
        /** Days of residuals behind the 80 % interval and P(up) (T−34 … T). */
        public int $residualWindowDays = 35,
        /** Rows start this many days after the season start (earlier values are flat padding). */
        public int $warmupDays = 13,
        /** No forecast below this many training rows. */
        public int $minimumTrainingRows = 100,
        /** |change| up to this is "stable". */
        public float $stableBand = 0.005,
        public float $lowQuantile = 0.1,
        public float $highQuantile = 0.9,
        /** Reasons kept besides the inertia. */
        public int $maximumReasons = 3,
        /** Reasons below this |impact| (a fraction: 0.001 = 0,1 pp) are dropped. */
        public float $minimumReasonImpact = 0.001,
    ) {}
}
```

`app/Services/ValueForecast/HybridRegression.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use InvalidArgumentException;
use RuntimeException;

/**
 * Ridge least squares by normal equations accumulated one row at a time, so
 * a walk-forward adds each day's rows once instead of refitting from scratch
 * (port of backtest.js `ols`). Column 0 is the intercept and is never
 * penalised.
 */
final class HybridRegression
{
    /** @var list<list<float>> */
    private array $xtx;

    /** @var list<float> */
    private array $xty;

    private int $count = 0;

    public function __construct(private readonly int $features, private readonly float $lambda)
    {
        $this->xtx = array_fill(0, $features, array_fill(0, $features, 0.0));
        $this->xty = array_fill(0, $features, 0.0);
    }

    /**
     * @param  list<float>  $x
     */
    public function add(array $x, float $y): void
    {
        if (count($x) !== $this->features) {
            throw new InvalidArgumentException("Expected {$this->features} features, got ".count($x).'.');
        }

        foreach ($x as $a => $xa) {
            if ($xa === 0.0) {
                continue;
            }

            $this->xty[$a] += $xa * $y;

            foreach ($x as $c => $xc) {
                $this->xtx[$a][$c] += $xa * $xc;
            }
        }

        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Gaussian elimination with partial pivoting.
     *
     * @return list<float>
     */
    public function solve(): array
    {
        $size = $this->features;
        $matrix = [];

        for ($a = 0; $a < $size; $a++) {
            $row = $this->xtx[$a];

            if ($a > 0) {
                $row[$a] += $this->lambda * $this->count;
            }

            $row[] = $this->xty[$a];
            $matrix[] = $row;
        }

        for ($i = 0; $i < $size; $i++) {
            $pivot = $i;

            for ($j = $i + 1; $j < $size; $j++) {
                if (abs($matrix[$j][$i]) > abs($matrix[$pivot][$i])) {
                    $pivot = $j;
                }
            }

            [$matrix[$i], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$i]];

            if (abs($matrix[$i][$i]) < 1e-15) {
                throw new RuntimeException('The value forecast regression is singular (too few rows).');
            }

            for ($j = $i + 1; $j < $size; $j++) {
                $factor = $matrix[$j][$i] / $matrix[$i][$i];

                for ($c = $i; $c <= $size; $c++) {
                    $matrix[$j][$c] -= $factor * $matrix[$i][$c];
                }
            }
        }

        $coefficients = array_fill(0, $size, 0.0);

        for ($i = $size - 1; $i >= 0; $i--) {
            $sum = $matrix[$i][$size];

            for ($c = $i + 1; $c < $size; $c++) {
                $sum -= $matrix[$i][$c] * $coefficients[$c];
            }

            // "+ 0.0" turns a -0.0 (an all-zero column) into 0.0.
            $coefficients[$i] = $sum / $matrix[$i][$i] + 0.0;
        }

        return $coefficients;
    }
}
```


- [ ] **Step 4: Run the tests**

Run: `herd php artisan test --compact tests/Unit/Services/HybridRegressionTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/ValueForecast/ValueForecastParameters.php app/Services/ValueForecast/HybridRegression.php tests/Unit/Services/HybridRegressionTest.php
git commit -m "feat: add the value forecast parameters and ridge regression"
```

---

### Task 4: The row and its 30 variables

**Files:**
- Create: `app/Services/ValueForecast/ValueForecastRow.php`, `app/Services/ValueForecast/ValueForecastFeatureVector.php`
- Test: `tests/Unit/Services/ValueForecastFeatureVectorTest.php`

**Interfaces:**
- Produces:
  - `ValueForecastRow` (readonly): `int $playerId, string $referenceDate, string $targetDate, int $value,
    float $changeToday, float $changeYesterday, float $changeBefore, float $marketChange,
    array{team: bool, played: bool, points: int} $matchYesterday, $matchToday, $matchBefore,
    int $daysToNextMatch, float $averagePoints, ?int $nextValue = null`; `actualChange(): ?float`.
  - `ValueForecastFeatureVector::SIZE = 30`, index constants (`CONSTANT … POINTS_X_LOG_VALUE`), `BUCKETS`,
    `bucket(array $match): ?string`, `of(ValueForecastRow): list<float>`,
    `target(ValueForecastRow, ValueForecastParameters): float`.

- [ ] **Step 1: Add the shared row builder to `tests/Pest.php`** (under "Functions"; Pest helpers must live there so a
  single test file can run on its own). Add `use App\Services\ValueForecast\ValueForecastRow;` at the top of the file.

```php
/**
 * A value forecast row with sensible defaults: a 10 M€ player rising 2 %
 * today, no matches around, next match in 5 days, 10,3 M€ tomorrow.
 *
 * @param  array<string, mixed>  $overrides
 */
function forecastRow(array $overrides = []): ValueForecastRow
{
    return new ValueForecastRow(...[
        'playerId' => 1,
        'referenceDate' => '2026-09-29',
        'targetDate' => '2026-09-30',
        'value' => 10_000_000,
        'changeToday' => 0.02,
        'changeYesterday' => 0.01,
        'changeBefore' => 0.005,
        'marketChange' => -0.007,
        'matchYesterday' => ['team' => false, 'played' => false, 'points' => 0],
        'matchToday' => ['team' => false, 'played' => false, 'points' => 0],
        'matchBefore' => ['team' => false, 'played' => false, 'points' => 0],
        'daysToNextMatch' => 5,
        'averagePoints' => 4.0,
        'nextValue' => 10_300_000,
        ...$overrides,
    ]);
}

```

- [ ] **Step 2: Write the failing test** — `tests/Unit/Services/ValueForecastFeatureVectorTest.php`:

```php
<?php

use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use App\Services\ValueForecast\ValueForecastParameters;

test('buckets a match like the research', function (array $match, ?string $bucket): void {
    expect(Vector::bucket($match))->toBe($bucket);
})->with([
    'no match' => [['team' => false, 'played' => false, 'points' => 0], null],
    'did not play' => [['team' => true, 'played' => false, 'points' => 0], 'nomin'],
    '2 pts' => [['team' => true, 'played' => true, 'points' => 2], 'low'],
    'negative' => [['team' => true, 'played' => true, 'points' => -1], 'low'],
    '5 pts' => [['team' => true, 'played' => true, 'points' => 5], 'mid'],
    '9 pts' => [['team' => true, 'played' => true, 'points' => 9], 'good'],
    '10 pts' => [['team' => true, 'played' => true, 'points' => 10], 'great'],
]);

test('builds the 30 variables in the research order', function (): void {
    $row = forecastRow([
        'value' => 10_000_000,
        'matchYesterday' => ['team' => true, 'played' => true, 'points' => 12],
        'matchToday' => ['team' => true, 'played' => false, 'points' => 0],
        'daysToNextMatch' => 1,
    ]);
    $logValue = 7.0 - 6.5;

    $x = Vector::of($row);

    expect($x)->toHaveCount(Vector::SIZE)
        ->and(array_slice($x, 0, 6))->toEqualWithDelta([1.0, 0.02, 0.01, 0.005, -0.007, 0.01], 1e-12)
        ->and(array_slice($x, Vector::YESTERDAY, 5))->toBe([0.0, 0.0, 0.0, 0.0, 1.0])
        ->and(array_slice($x, Vector::TODAY, 5))->toBe([1.0, 0.0, 0.0, 0.0, 0.0])
        ->and(array_slice($x, Vector::BEFORE, 5))->toBe([0.0, 0.0, 0.0, 0.0, 0.0])
        ->and($x[Vector::POINTS_VS_AVERAGE])->toEqualWithDelta(0.8, 1e-12)
        ->and([$x[Vector::MATCH_TOMORROW], $x[Vector::MATCH_WITHIN_3_DAYS], $x[Vector::BREAK_OVER_7_DAYS]])->toBe([1.0, 1.0, 0.0])
        ->and($x[Vector::LOG_VALUE])->toEqualWithDelta($logValue, 1e-12)
        ->and($x[Vector::CHANGE_X_LOG_VALUE])->toEqualWithDelta(0.02 * $logValue, 1e-12)
        ->and($x[Vector::AT_FLOOR])->toBe(0.0)
        ->and($x[Vector::CHANGE_IF_MATCH_YESTERDAY])->toEqualWithDelta(0.02, 1e-12)
        ->and($x[Vector::POINTS_X_LOG_VALUE])->toEqualWithDelta(1.2 * $logValue, 1e-12);
});

test('clamps yesterday\'s points to −5…20 in the interaction and flags the floor', function (): void {
    $x = Vector::of(forecastRow([
        'changeToday' => -0.034,
        'matchYesterday' => ['team' => true, 'played' => true, 'points' => 25],
        'daysToNextMatch' => 12,
    ]));

    expect($x[Vector::POINTS_X_LOG_VALUE])->toEqualWithDelta(2.0 * 0.5, 1e-12)
        ->and($x[Vector::AT_FLOOR])->toBe(1.0)
        ->and($x[Vector::BREAK_OVER_7_DAYS])->toBe(1.0);
});

test('the fitted target is the change over persistence, clipped to ±10 %', function (): void {
    $parameters = new ValueForecastParameters;

    expect(Vector::target(forecastRow(['nextValue' => 10_300_000]), $parameters))->toEqualWithDelta(0.03 - 0.02, 1e-12)
        ->and(Vector::target(forecastRow(['nextValue' => 13_000_000]), $parameters))->toBe(0.1)
        ->and(forecastRow(['nextValue' => null])->actualChange())->toBeNull();
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Unit/Services/ValueForecastFeatureVectorTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Implement**

`app/Services/ValueForecast/ValueForecastRow.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

/**
 * What is known about one player at the end of `referenceDate` (T), to
 * forecast the value dated `targetDate` (T + 1). Changes are fractions
 * (0.02 = +2 %). A match array says whether his team played a finished
 * match that day, whether he played minutes, and his fantasy points
 * (pending points count as 0).
 *
 * @phpstan-type MatchInfo array{team: bool, played: bool, points: int}
 */
final readonly class ValueForecastRow
{
    /**
     * @param  MatchInfo  $matchYesterday  T − 1
     * @param  MatchInfo  $matchToday  T
     * @param  MatchInfo  $matchBefore  T − 2
     */
    public function __construct(
        public int $playerId,
        public string $referenceDate,
        public string $targetDate,
        public int $value,
        public float $changeToday,
        public float $changeYesterday,
        public float $changeBefore,
        /** Mean daily change of every player with values on T and T − 1. */
        public float $marketChange,
        public array $matchYesterday,
        public array $matchToday,
        public array $matchBefore,
        /** Days from T to his team's next match (any state), at most 30. */
        public int $daysToNextMatch,
        /** Mean fantasy points of his matches with minutes before T. */
        public float $averagePoints,
        /** The published value on T + 1, null while unknown. */
        public ?int $nextValue = null,
    ) {}

    public function actualChange(): ?float
    {
        return $this->nextValue === null ? null : ($this->nextValue - $this->value) / $this->value;
    }
}
```

`app/Services/ValueForecast/ValueForecastFeatureVector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

/**
 * The 30 variables of the hybrid model, in the fixed order of the research
 * (comando-lechuga-research/value-forecast/backtest.js `feats`).
 *
 * @phpstan-import-type MatchInfo from ValueForecastRow
 */
final class ValueForecastFeatureVector
{
    public const int SIZE = 30;

    /** @var list<string> */
    public const array BUCKETS = ['nomin', 'low', 'mid', 'good', 'great'];

    public const int CONSTANT = 0;

    public const int CHANGE_TODAY = 1;

    public const int CHANGE_YESTERDAY = 2;

    public const int CHANGE_BEFORE = 3;

    public const int MARKET = 4;

    public const int ACCELERATION = 5;

    /** First of the five buckets of T − 1's match (6…10). */
    public const int YESTERDAY = 6;

    /** First of the five buckets of T's match (11…15). */
    public const int TODAY = 11;

    /** First of the five buckets of T − 2's match (16…20). */
    public const int BEFORE = 16;

    public const int POINTS_VS_AVERAGE = 21;

    public const int MATCH_TOMORROW = 22;

    public const int MATCH_WITHIN_3_DAYS = 23;

    public const int BREAK_OVER_7_DAYS = 24;

    public const int LOG_VALUE = 25;

    public const int CHANGE_X_LOG_VALUE = 26;

    public const int AT_FLOOR = 27;

    public const int CHANGE_IF_MATCH_YESTERDAY = 28;

    public const int POINTS_X_LOG_VALUE = 29;

    /**
     * @param  MatchInfo  $match
     */
    public static function bucket(array $match): ?string
    {
        return match (true) {
            !$match['team'] => null,
            !$match['played'] => 'nomin',
            $match['points'] <= 2 => 'low',
            $match['points'] <= 5 => 'mid',
            $match['points'] <= 9 => 'good',
            default => 'great',
        };
    }

    /**
     * @return list<float>
     */
    public static function of(ValueForecastRow $row): array
    {
        $logValue = log10(max($row->value, 1)) - 6.5;
        $x = [1.0, $row->changeToday, $row->changeYesterday, $row->changeBefore, $row->marketChange, $row->changeToday - $row->changeYesterday];

        foreach ([$row->matchYesterday, $row->matchToday, $row->matchBefore] as $match) {
            $bucket = self::bucket($match);

            foreach (self::BUCKETS as $name) {
                $x[] = $bucket === $name ? 1.0 : 0.0;
            }
        }

        $yesterday = $row->matchYesterday;
        $x[] = $yesterday['played'] ? ($yesterday['points'] - $row->averagePoints) / 10 : 0.0;
        $x[] = $row->daysToNextMatch <= 1 ? 1.0 : 0.0;
        $x[] = $row->daysToNextMatch <= 3 ? 1.0 : 0.0;
        $x[] = $row->daysToNextMatch > 7 ? 1.0 : 0.0;
        $x[] = $logValue;
        $x[] = $row->changeToday * $logValue;
        $x[] = $row->changeToday < -0.03 ? 1.0 : 0.0;
        $x[] = $yesterday['team'] ? $row->changeToday : 0.0;
        $x[] = $yesterday['played'] ? max(-5, min(20, $yesterday['points'])) / 10 * $logValue : 0.0;

        return $x;
    }

    /** What the regression fits: tomorrow's change over persistence, clipped. Rows need a known outcome. */
    public static function target(ValueForecastRow $row, ValueForecastParameters $parameters): float
    {
        $actual = $row->actualChange() ?? throw new LogicException('A training row needs a known next value.');

        return max(-$parameters->targetClip, min($parameters->targetClip, $actual - $row->changeToday));
    }
}
```

(Add `use LogicException;` under the namespace.)

- [ ] **Step 5: Run the tests**

Run: `herd php artisan test --compact tests/Unit/Services/ValueForecastFeatureVectorTest.php`
Expected: PASS.

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/ValueForecast/ValueForecastRow.php app/Services/ValueForecast/ValueForecastFeatureVector.php tests/Pest.php tests/Unit/Services/ValueForecastFeatureVectorTest.php
git commit -m "feat: add the value forecast row and its variables"
```

---

### Task 5: Rows from the database

**Files:**
- Create: `app/Services/ValueForecast/ValueForecastFeatures.php`
- Modify: `tests/Pest.php` (add `forecastPlayer()`, see File Structure)
- Test: `tests/Feature/Services/ValueForecastFeaturesTest.php`

**Interfaces:**
- Consumes: `ValueForecastRow`, `ValueForecastParameters` (Tasks 3–4).
- Produces: `ValueForecastFeatures::rows(Season $season, string $lastReferenceDate): list<ValueForecastRow>` — every
  player-day with `referenceDate` in `[season.start_date + warmupDays, $lastReferenceDate]`, sorted by
  `(referenceDate, playerId)`; `nextValue` filled when the value of `referenceDate + 1` exists and is > 0. Includes
  every player with market values (also `out_of_league`: the market mean and the research do); callers filter.

- [ ] **Step 1: Add `forecastPlayer()` to `tests/Pest.php`** (code in File Structure above).

- [ ] **Step 2: Write the failing test**

```php
<?php

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\ValueForecast\ValueForecastFeatures;
use App\Services\ValueForecast\ValueForecastRow;

beforeEach(function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
});

/**
 * @param  list<ValueForecastRow>  $rows
 */
function onlyRowOf(array $rows, Player $player): ValueForecastRow
{
    $mine = array_values(array_filter($rows, fn (ValueForecastRow $row): bool => $row->playerId === $player->id));
    expect($mine)->toHaveCount(1);

    return $mine[0];
}

test('builds a row per player-day with its changes, the market mean and the next value', function (): void {
    $riser = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_500_000], '2026-09-24');
    $flat = forecastPlayer($this->season, array_fill(0, 5, 5_000_000), '2026-09-24');

    $rows = app(ValueForecastFeatures::class)->rows($this->season, '2026-09-23');
    $row = onlyRowOf($rows, $riser);

    expect($rows)->toHaveCount(2)
        ->and($row->referenceDate)->toBe('2026-09-23')
        ->and($row->targetDate)->toBe('2026-09-24')
        ->and($row->value)->toBe(10_300_000)
        ->and($row->changeToday)->toEqualWithDelta(100_000 / 10_200_000, 1e-12)
        ->and($row->changeYesterday)->toEqualWithDelta(100_000 / 10_100_000, 1e-12)
        ->and($row->changeBefore)->toEqualWithDelta(100_000 / 10_000_000, 1e-12)
        ->and($row->marketChange)->toEqualWithDelta((100_000 / 10_200_000) / 2, 1e-12)
        ->and($row->nextValue)->toBe(10_500_000)
        ->and($row->matchYesterday)->toBe(['team' => false, 'played' => false, 'points' => 0])
        ->and($row->daysToNextMatch)->toBe(30)
        ->and(onlyRowOf($rows, $flat)->changeToday)->toBe(0.0);
});

test('reads yesterday, today and the day before\'s matches, the next match and the average with minutes', function (): void {
    $player = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], '2026-09-23');
    $rival = Team::factory()->create();
    $match = fn (string $date, FixtureState $state = FixtureState::Finished): Fixture => Fixture::factory()->create([
        'season_id' => $this->season->id,
        'team_local_id' => $player->team_id,
        'team_guest_id' => $rival->id,
        'date' => "{$date} 18:00:00",
        'state' => $state,
    ]);
    $lineup = fn (Fixture $fixture, ?int $points, int $minutes): FixtureLineup => FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $player->team_id,
        'fantasy_points' => $points,
        'fantasy_stats' => ['mins_played' => [$minutes, 2]],
    ]);
    $lineup($match('2026-09-15'), 8, 0);      // no minutes: left out of the average
    $lineup($match('2026-09-21'), null, 30);  // pending points count as 0
    $lineup($match('2026-09-22'), 12, 90);
    $match('2026-09-23');                     // his team played, he has no lineup
    $match('2026-09-27', FixtureState::Scheduled);

    $row = onlyRowOf(app(ValueForecastFeatures::class)->rows($this->season, '2026-09-23'), $player);

    expect($row->matchYesterday)->toBe(['team' => true, 'played' => true, 'points' => 12])
        ->and($row->matchToday)->toBe(['team' => true, 'played' => false, 'points' => 0])
        ->and($row->matchBefore)->toBe(['team' => true, 'played' => true, 'points' => 0])
        ->and($row->daysToNextMatch)->toBe(4)
        ->and($row->averagePoints)->toBe(6.0)
        ->and($row->nextValue)->toBeNull();
});

test('skips a player-day with a missing or zero value around it and leaves an unusable next value unknown', function (): void {
    $gap = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], '2026-09-23');
    PlayerMarket::query()->where('player_id', $gap->id)->whereDate('date', '2026-09-21')->delete();
    forecastPlayer($this->season, [10_000_000, 0, 10_200_000, 10_300_000], '2026-09-23');
    $zeroNext = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 0], '2026-09-24');

    $rows = app(ValueForecastFeatures::class)->rows($this->season, '2026-09-23');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->playerId)->toBe($zeroNext->id)
        ->and($rows[0]->nextValue)->toBeNull();
});

test('starts 13 days after the season start', function (): void {
    $this->season->update(['start_date' => '2026-09-15']);
    forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000], '2026-09-28');

    $rows = app(ValueForecastFeatures::class)->rows($this->season->fresh(), '2026-09-28');

    expect(array_map(fn (ValueForecastRow $row): string => $row->referenceDate, $rows))->toBe(['2026-09-28']);
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Services/ValueForecastFeaturesTest.php`
Expected: FAIL — `Class "App\Services\ValueForecast\ValueForecastFeatures" not found`.

- [ ] **Step 4: Implement** — `app/Services/ValueForecast/ValueForecastFeatures.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\FixtureState;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds every value forecast row of a season with a handful of queries,
 * as plain scalars (no models), like season:backtest-max-bid does. Same
 * rules as the research export (comando-lechuga-research/value-forecast/
 * backtest.js): a player's matches are those of his current team, match
 * dates are the UTC date of `fixtures.date`, pending points count as 0.
 *
 * @phpstan-import-type MatchInfo from ValueForecastRow
 */
final class ValueForecastFeatures
{
    /** Days of values a row needs before its reference date (T − 3 … T). */
    private const int LOOKBACK_DAYS = 3;

    /** @var array<string, string> */
    private array $shifted = [];

    public function __construct(private readonly ValueForecastParameters $parameters = new ValueForecastParameters) {}

    /**
     * @return list<ValueForecastRow>
     */
    public function rows(Season $season, string $lastReferenceDate): array
    {
        $firstReference = $season->start_date->addDays($this->parameters->warmupDays)->toDateString();

        if ($firstReference > $lastReferenceDate) {
            return [];
        }

        $values = $this->values($this->shift($firstReference, -self::LOOKBACK_DAYS), $this->shift($lastReferenceDate, 1));
        $teams = DB::table('players')->pluck('team_id', 'id')->all();
        [$matchDates, $finishedDates] = $this->teamMatches($season);
        [$lineups, $playedPoints] = $this->lineups($season);
        $marketChanges = $this->marketChanges($values);
        $rows = [];

        foreach ($values as $playerId => $byDate) {
            $teamId = isset($teams[$playerId]) ? (int) $teams[$playerId] : null;

            if ($teamId === null) {
                continue;
            }

            foreach ($byDate as $date => $value) {
                if ($date < $firstReference || $date > $lastReferenceDate) {
                    continue;
                }

                $changeToday = self::change($byDate, $date, $this->shift($date, -1));
                $changeYesterday = self::change($byDate, $this->shift($date, -1), $this->shift($date, -2));
                $changeBefore = self::change($byDate, $this->shift($date, -2), $this->shift($date, -3));

                if ($changeToday === null || $changeYesterday === null || $changeBefore === null) {
                    continue;
                }

                $target = $this->shift($date, 1);
                $next = $byDate[$target] ?? null;

                $rows[] = new ValueForecastRow(
                    playerId: $playerId,
                    referenceDate: $date,
                    targetDate: $target,
                    value: $value,
                    changeToday: $changeToday,
                    changeYesterday: $changeYesterday,
                    changeBefore: $changeBefore,
                    marketChange: $marketChanges[$date] ?? 0.0,
                    matchYesterday: self::match($lineups[$playerId] ?? [], $finishedDates[$teamId] ?? [], $this->shift($date, -1)),
                    matchToday: self::match($lineups[$playerId] ?? [], $finishedDates[$teamId] ?? [], $date),
                    matchBefore: self::match($lineups[$playerId] ?? [], $finishedDates[$teamId] ?? [], $this->shift($date, -2)),
                    daysToNextMatch: self::daysToNextMatch($matchDates[$teamId] ?? [], $date),
                    averagePoints: self::averagePoints($playedPoints[$playerId] ?? [], $date),
                    nextValue: $next !== null && $next > 0 ? $next : null,
                );
            }
        }

        usort($rows, fn (ValueForecastRow $a, ValueForecastRow $b): int => [$a->referenceDate, $a->playerId] <=> [$b->referenceDate, $b->playerId]);

        return $rows;
    }

    /**
     * @return array<int, array<string, int>> player id → date → value, oldest first
     */
    private function values(string $from, string $to): array
    {
        $values = [];

        foreach (
            DB::table('player_markets')
                ->whereDate('date', '>=', $from)
                ->whereDate('date', '<=', $to)
                ->orderBy('date')
                ->select(['player_id', 'date', 'value'])
                ->cursor() as $row
        ) {
            $values[(int) $row->player_id][substr((string) $row->date, 0, 10)] = (int) $row->value;
        }

        return $values;
    }

    /**
     * Every match date of each team (any state, soonest first) and the dates
     * of its finished matches.
     *
     * @return array{0: array<int, list<string>>, 1: array<int, array<string, true>>}
     */
    private function teamMatches(Season $season): array
    {
        $all = [];
        $finished = [];

        foreach (
            DB::table('fixtures')
                ->where('season_id', $season->id)
                ->whereNotNull('date')
                ->orderBy('date')
                ->get(['date', 'team_local_id', 'team_guest_id', 'state']) as $fixture
        ) {
            $date = substr((string) $fixture->date, 0, 10);

            foreach ([(int) $fixture->team_local_id, (int) $fixture->team_guest_id] as $teamId) {
                $all[$teamId][] = $date;

                if ($fixture->state === FixtureState::Finished->value) {
                    $finished[$teamId][$date] = true;
                }
            }
        }

        return [$all, $finished];
    }

    /**
     * Each player's finished lineups by date (the first one of a date wins)
     * and, oldest first, the points of those he played minutes in.
     *
     * @return array{0: array<int, array<string, array{played: bool, points: int}>>, 1: array<int, list<array{date: string, points: int}>>}
     */
    private function lineups(Season $season): array
    {
        $byDate = [];
        $played = [];

        foreach (
            DB::table('fixture_lineups')
                ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
                ->where('fixtures.season_id', $season->id)
                ->where('fixtures.state', FixtureState::Finished->value)
                ->whereNotNull('fixture_lineups.player_id')
                ->orderBy('fixtures.date')
                ->get(['fixture_lineups.player_id', 'fixtures.date', 'fixture_lineups.fantasy_points', 'fixture_lineups.fantasy_stats']) as $lineup
        ) {
            $playerId = (int) $lineup->player_id;
            $date = substr((string) $lineup->date, 0, 10);
            $stats = is_string($lineup->fantasy_stats) ? json_decode($lineup->fantasy_stats, true) : null;
            $minutes = is_array($stats) ? (int) ($stats['mins_played'][0] ?? 0) : 0;
            $points = (int) ($lineup->fantasy_points ?? 0);

            $byDate[$playerId][$date] ??= ['played' => $minutes > 0, 'points' => $points];

            if ($minutes > 0) {
                $played[$playerId][] = ['date' => $date, 'points' => $points];
            }
        }

        return [$byDate, $played];
    }

    /**
     * Mean daily change of every player with values on a date and the day before.
     *
     * @param  array<int, array<string, int>>  $values
     * @return array<string, float>
     */
    private function marketChanges(array $values): array
    {
        $sums = [];
        $counts = [];

        foreach ($values as $byDate) {
            foreach (array_keys($byDate) as $date) {
                $change = self::change($byDate, $date, $this->shift($date, -1));

                if ($change === null) {
                    continue;
                }

                $sums[$date] = ($sums[$date] ?? 0.0) + $change;
                $counts[$date] = ($counts[$date] ?? 0) + 1;
            }
        }

        $means = [];

        foreach ($sums as $date => $sum) {
            $means[$date] = $sum / $counts[$date];
        }

        return $means;
    }

    /**
     * @param  array<string, int>  $byDate
     */
    private static function change(array $byDate, string $date, string $previousDate): ?float
    {
        $current = $byDate[$date] ?? 0;
        $previous = $byDate[$previousDate] ?? 0;

        return $current > 0 && $previous > 0 ? ($current - $previous) / $previous : null;
    }

    /**
     * @param  array<string, array{played: bool, points: int}>  $lineups
     * @param  array<string, true>  $finishedDates
     * @return MatchInfo
     */
    private static function match(array $lineups, array $finishedDates, string $date): array
    {
        $own = $lineups[$date] ?? null;

        if ($own !== null) {
            return ['team' => true, 'played' => $own['played'], 'points' => $own['points']];
        }

        return isset($finishedDates[$date])
            ? ['team' => true, 'played' => false, 'points' => 0]
            : ['team' => false, 'played' => false, 'points' => 0];
    }

    /**
     * @param  list<string>  $matchDates  soonest first
     */
    private static function daysToNextMatch(array $matchDates, string $date): int
    {
        foreach ($matchDates as $matchDate) {
            if ($matchDate > $date) {
                return min(30, (int) CarbonImmutable::parse($date)->diffInDays(CarbonImmutable::parse($matchDate)));
            }
        }

        return 30;
    }

    /**
     * @param  list<array{date: string, points: int}>  $played  oldest first
     */
    private static function averagePoints(array $played, string $date): float
    {
        $sum = 0;
        $count = 0;

        foreach ($played as $match) {
            if ($match['date'] >= $date) {
                break;
            }

            $sum += $match['points'];
            $count++;
        }

        return $count === 0 ? 0.0 : $sum / $count;
    }

    private function shift(string $date, int $days): string
    {
        return $this->shifted["{$date}|{$days}"] ??= CarbonImmutable::parse($date)->addDays($days)->toDateString();
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Services/ValueForecastFeaturesTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/ValueForecast/ValueForecastFeatures.php tests/Pest.php tests/Feature/Services/ValueForecastFeaturesTest.php
git commit -m "feat: build value forecast rows from the market and match history"
```

---

### Task 6: Prediction, interval, P(up) and reasons

**Files:**
- Create: `app/Services/ValueForecast/ValueForecastModel.php`, `app/Services/ValueForecast/ValueForecastPrediction.php`
- Test: `tests/Unit/Services/ValueForecastModelTest.php`

**Interfaces:**
- Consumes: `ValueForecastFeatureVector::of/SIZE/*` constants, `ValueForecastRow`, `ValueForecastParameters`.
- Produces:
  - `ValueForecastModel::__construct(list<float> $coefficients, ValueForecastParameters $parameters)`;
    `change(ValueForecastRow): float`; `withResiduals(iterable<ValueForecastRow>): self`;
    `predict(ValueForecastRow): ValueForecastPrediction`;
    `quantiles(): array{match_yesterday: array{size: int, low: float|null, high: float|null}, no_match_yesterday: array{size: int, low: float|null, high: float|null}}`.
  - `ValueForecastPrediction` (readonly): `ValueForecastRow $row, float $change, float $lowChange, float $highChange,
    float $upProbability, list<array{kind: string, label: string, impact_pct: float}> $reasons`;
    `predictedValue(): int`, `low(): int`, `high(): int`, `direction(float $stableBand): 'up'|'stable'|'down'`.
  - Reason kinds: `inertia` (always first), then up to 3 of `streak`, `market`, `match_yesterday`, `match_today`,
    `match_before`, `calendar`, `baseline`, `floor`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use App\Services\ValueForecast\ValueForecastModel;
use App\Services\ValueForecast\ValueForecastParameters;

/**
 * @param  array<int, float>  $weights  feature index → coefficient
 */
function forecastModel(array $weights = []): ValueForecastModel
{
    $coefficients = array_fill(0, Vector::SIZE, 0.0);

    foreach ($weights as $index => $weight) {
        $coefficients[$index] = $weight;
    }

    return new ValueForecastModel($coefficients, new ValueForecastParameters);
}

test('with zero coefficients tomorrow repeats today\'s change', function (): void {
    $prediction = forecastModel()->predict(forecastRow(['changeToday' => 0.02]));

    expect($prediction->change)->toBe(0.02)
        ->and($prediction->predictedValue())->toBe(10_200_000)
        ->and($prediction->direction(0.005))->toBe('up')
        ->and($prediction->reasons)->toBe([['kind' => 'inertia', 'label' => 'Inercia: cambio de hoy', 'impact_pct' => 2.0]]);
});

test('never forecasts below the daily floor and says so', function (): void {
    $prediction = forecastModel([Vector::CONSTANT => -0.05])->predict(forecastRow(['changeToday' => -0.02]));

    expect($prediction->change)->toBe(-0.0346)
        ->and($prediction->direction(0.005))->toBe('down')
        ->and(array_column($prediction->reasons, 'kind'))->toContain('floor');
});

test('adds a great match yesterday as the top reason with its date and points', function (): void {
    $row = forecastRow([
        'referenceDate' => '2026-09-29',
        'changeToday' => -0.01,
        'matchYesterday' => ['team' => true, 'played' => true, 'points' => 12],
    ]);

    $prediction = forecastModel([Vector::YESTERDAY + 4 => 0.04, Vector::MARKET => 0.5])->predict($row);

    expect($prediction->change)->toEqualWithDelta(-0.01 + 0.04 + 0.5 * -0.007, 1e-12)
        ->and($prediction->reasons[0]['kind'])->toBe('inertia')
        ->and($prediction->reasons[1])->toBe(['kind' => 'match_yesterday', 'label' => 'Partido del 28/09 · 12 pts', 'impact_pct' => 4.0])
        ->and($prediction->reasons[2])->toBe(['kind' => 'market', 'label' => 'Mercado general', 'impact_pct' => -0.35]);
});

test('keeps at most three reasons besides the inertia, dropping those under 0,1 pp', function (): void {
    $prediction = forecastModel([
        Vector::CONSTANT => 0.011,
        Vector::MARKET => 1.0,
        Vector::CHANGE_YESTERDAY => -1.0,
        Vector::BREAK_OVER_7_DAYS => 0.02,
        Vector::MATCH_TOMORROW => 0.0005,
    ])->predict(forecastRow(['daysToNextMatch' => 12]));

    expect(array_column($prediction->reasons, 'kind'))->toBe(['inertia', 'calendar', 'baseline', 'streak'])
        ->and($prediction->reasons[1]['label'])->toBe('Próximo partido en 12 días')
        ->and($prediction->reasons[3]['label'])->toBe('Freno de racha');
});

test('the interval and P(up) come from the residuals of the rows\' group', function (): void {
    $model = forecastModel();
    // Residuals of "no match yesterday" rows: actual − 0,02 persistence → −0,01 … +0,03 in 0,01 steps.
    $history = array_map(
        fn (float $actual): mixed => forecastRow(['changeToday' => 0.02, 'nextValue' => (int) round(10_000_000 * (1 + $actual))]),
        [0.01, 0.02, 0.03, 0.04, 0.05],
    );

    $prediction = $model->withResiduals($history)->predict(forecastRow(['changeToday' => 0.005]));

    // Sorted residuals [−0,01, 0, 0,01, 0,02, 0,03]: q10 = index floor(0,1·4) = 0 → −0,01; q90 = index 3 → 0,02.
    expect($prediction->lowChange)->toEqualWithDelta(-0.005, 1e-9)
        ->and($prediction->highChange)->toEqualWithDelta(0.025, 1e-9)
        ->and($prediction->low())->toBe(9_950_000)
        ->and($prediction->high())->toBe(10_250_000)
        ->and($prediction->upProbability)->toBe(0.8)
        ->and($prediction->direction(0.005))->toBe('stable');
});

test('a row whose team played yesterday uses that group, or the other one while it is empty', function (): void {
    $model = forecastModel()->withResiduals([forecastRow(['changeToday' => 0.0, 'nextValue' => 10_100_000])]);
    $afterMatch = forecastRow(['changeToday' => 0.0, 'matchYesterday' => ['team' => true, 'played' => false, 'points' => 0]]);

    expect($model->predict($afterMatch)->highChange)->toEqualWithDelta(0.01, 1e-9)
        ->and($model->quantiles()['match_yesterday']['size'])->toBe(0)
        ->and($model->quantiles()['no_match_yesterday']['size'])->toBe(1);
});
```

Worked numbers. Reasons test: inertia = `changeToday` −1 % → −1.0; yesterday's bucket `great` × 0,04 = +4 pp;
market 0,5 × −0,007 = −0,35 pp; the rest are 0 and dropped. "At most three" test (weights on `log_value` are 0):
calendar (break > 7 days) 0,02 → 2 pp; baseline 0,011 → 1,1 pp; streak (`changeYesterday` −1 × 0,01) → −1 pp;
market 1 × −0,007 → −0,7 pp is the fourth and is dropped; match tomorrow is 0 (12 days away).

- [ ] **Step 2: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Unit/Services/ValueForecastModelTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

`app/Services/ValueForecast/ValueForecastPrediction.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

/**
 * Tomorrow's forecast for one row: the change (a fraction), its 80 %
 * interval, the chance it goes up and the reasons with their impact.
 */
final readonly class ValueForecastPrediction
{
    /**
     * @param  list<array{kind: string, label: string, impact_pct: float}>  $reasons
     */
    public function __construct(
        public ValueForecastRow $row,
        public float $change,
        public float $lowChange,
        public float $highChange,
        public float $upProbability,
        public array $reasons,
    ) {}

    public function predictedValue(): int
    {
        return (int) round($this->row->value * (1 + $this->change));
    }

    public function low(): int
    {
        return (int) round($this->row->value * (1 + $this->lowChange));
    }

    public function high(): int
    {
        return (int) round($this->row->value * (1 + $this->highChange));
    }

    /**
     * @return 'up'|'stable'|'down'
     */
    public function direction(float $stableBand): string
    {
        return match (true) {
            $this->change > $stableBand => 'up',
            $this->change < -$stableBand => 'down',
            default => 'stable',
        };
    }
}
```

`app/Services/ValueForecast/ValueForecastModel.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The hybrid "persistence + match shock" model for one fit: tomorrow's
 * change = max(floor, today's change + w·x), an 80 % interval and P(up)
 * from recent residuals split by "his team played yesterday", and the
 * reasons behind it (spec §1.3–1.4).
 *
 * @phpstan-import-type MatchInfo from ValueForecastRow
 */
final class ValueForecastModel
{
    /** @var array<string, list<int>> Reason kind → the variables it adds up. */
    private const array GROUPS = [
        'streak' => [Vector::CHANGE_TODAY, Vector::CHANGE_YESTERDAY, Vector::CHANGE_BEFORE, Vector::ACCELERATION, Vector::CHANGE_X_LOG_VALUE, Vector::AT_FLOOR, Vector::CHANGE_IF_MATCH_YESTERDAY],
        'market' => [Vector::MARKET],
        'match_yesterday' => [Vector::YESTERDAY, Vector::YESTERDAY + 1, Vector::YESTERDAY + 2, Vector::YESTERDAY + 3, Vector::YESTERDAY + 4, Vector::POINTS_VS_AVERAGE, Vector::POINTS_X_LOG_VALUE],
        'match_today' => [Vector::TODAY, Vector::TODAY + 1, Vector::TODAY + 2, Vector::TODAY + 3, Vector::TODAY + 4],
        'match_before' => [Vector::BEFORE, Vector::BEFORE + 1, Vector::BEFORE + 2, Vector::BEFORE + 3, Vector::BEFORE + 4],
        'calendar' => [Vector::MATCH_TOMORROW, Vector::MATCH_WITHIN_3_DAYS, Vector::BREAK_OVER_7_DAYS],
        'baseline' => [Vector::CONSTANT, Vector::LOG_VALUE],
    ];

    /** @var array{0: list<float>, 1: list<float>} Sorted residuals: [no match yesterday, match yesterday]. */
    private array $residuals = [[], []];

    /**
     * @param  list<float>  $coefficients
     */
    public function __construct(
        public readonly array $coefficients,
        private readonly ValueForecastParameters $parameters,
    ) {}

    public function change(ValueForecastRow $row): float
    {
        return max($this->parameters->floor, $this->rawChange(Vector::of($row), $row));
    }

    /**
     * A copy whose interval and P(up) come from these rows' residuals
     * (rows without a known outcome are skipped).
     *
     * @param  iterable<ValueForecastRow>  $rows
     */
    public function withResiduals(iterable $rows): self
    {
        $model = clone $this;
        $model->residuals = [[], []];

        foreach ($rows as $row) {
            $actual = $row->actualChange();

            if ($actual !== null) {
                $model->residuals[$row->matchYesterday['team'] ? 1 : 0][] = $actual - $this->change($row);
            }
        }

        sort($model->residuals[0]);
        sort($model->residuals[1]);

        return $model;
    }

    public function predict(ValueForecastRow $row): ValueForecastPrediction
    {
        $x = Vector::of($row);
        $raw = $this->rawChange($x, $row);
        $change = max($this->parameters->floor, $raw);
        $pool = $this->pool($row);

        if ($pool === []) {
            [$low, $high, $upProbability] = [$change, $change, $change > 0 ? 1.0 : 0.0];
        } else {
            $low = max($this->parameters->floor, $change + self::quantile($pool, $this->parameters->lowQuantile));
            $high = $change + self::quantile($pool, $this->parameters->highQuantile);
            $upProbability = (count($pool) - self::countAtMost($pool, -$change)) / count($pool);
        }

        return new ValueForecastPrediction($row, $change, $low, $high, $upProbability, $this->reasons($row, $x, $raw, $change));
    }

    /**
     * @return array{match_yesterday: array{size: int, low: float|null, high: float|null}, no_match_yesterday: array{size: int, low: float|null, high: float|null}}
     */
    public function quantiles(): array
    {
        $describe = fn (array $pool): array => [
            'size' => count($pool),
            'low' => $pool === [] ? null : self::quantile($pool, $this->parameters->lowQuantile),
            'high' => $pool === [] ? null : self::quantile($pool, $this->parameters->highQuantile),
        ];

        return ['match_yesterday' => $describe($this->residuals[1]), 'no_match_yesterday' => $describe($this->residuals[0])];
    }

    /**
     * @param  list<float>  $x
     */
    private function rawChange(array $x, ValueForecastRow $row): float
    {
        $change = $row->changeToday;

        foreach ($this->coefficients as $index => $coefficient) {
            $change += $coefficient * $x[$index];
        }

        return $change;
    }

    /**
     * @return list<float>
     */
    private function pool(ValueForecastRow $row): array
    {
        $group = $this->residuals[$row->matchYesterday['team'] ? 1 : 0];

        return $group !== [] ? $group : $this->residuals[0];
    }

    /**
     * @param  list<float>  $sorted
     */
    private static function quantile(array $sorted, float $probability): float
    {
        return $sorted[(int) floor($probability * (count($sorted) - 1))];
    }

    /**
     * How many sorted values are ≤ `$threshold` (binary search).
     *
     * @param  list<float>  $sorted
     */
    private static function countAtMost(array $sorted, float $threshold): int
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($sorted[$middle] <= $threshold) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * @param  list<float>  $x
     * @return list<array{kind: string, label: string, impact_pct: float}>
     */
    private function reasons(ValueForecastRow $row, array $x, float $raw, float $change): array
    {
        $impacts = [];

        foreach (self::GROUPS as $kind => $indexes) {
            $impact = 0.0;

            foreach ($indexes as $index) {
                $impact += $this->coefficients[$index] * $x[$index];
            }

            $impacts[$kind] = $impact;
        }

        if ($change > $raw) {
            $impacts['floor'] = $change - $raw;
        }

        $impacts = array_filter($impacts, fn (float $impact): bool => abs($impact) >= $this->parameters->minimumReasonImpact);
        uasort($impacts, fn (float $a, float $b): int => abs($b) <=> abs($a));

        $reasons = [self::reason('inertia', $row->changeToday, $row)];

        foreach (array_slice($impacts, 0, $this->parameters->maximumReasons, true) as $kind => $impact) {
            $reasons[] = self::reason($kind, $impact, $row);
        }

        return $reasons;
    }

    /**
     * @return array{kind: string, label: string, impact_pct: float}
     */
    private static function reason(string $kind, float $impact, ValueForecastRow $row): array
    {
        return ['kind' => $kind, 'label' => self::label($kind, $impact, $row), 'impact_pct' => round($impact * 100, 2)];
    }

    private static function label(string $kind, float $impact, ValueForecastRow $row): string
    {
        $day = fn (int $offset): string => CarbonImmutable::parse($row->referenceDate)->addDays($offset)->format('d/m');

        return match ($kind) {
            'inertia' => 'Inercia: cambio de hoy',
            'streak' => $impact < 0 ? 'Freno de racha' : 'Racha',
            'market' => 'Mercado general',
            'match_yesterday' => self::matchLabel('Partido del '.$day(-1), $row->matchYesterday),
            'match_today' => self::matchLabel('Partido de hoy', $row->matchToday),
            'match_before' => self::matchLabel('Partido del '.$day(-2), $row->matchBefore),
            'calendar' => match (true) {
                $row->daysToNextMatch >= 30 => 'Sin partido próximo',
                $row->daysToNextMatch === 1 => 'Próximo partido en 1 día',
                default => "Próximo partido en {$row->daysToNextMatch} días",
            },
            'baseline' => 'Nivel de valor',
            'floor' => 'Suelo diario −3,46 %',
            default => throw new LogicException("Unknown value forecast reason {$kind}."),
        };
    }

    /**
     * @param  MatchInfo  $match
     */
    private static function matchLabel(string $prefix, array $match): string
    {
        return $match['played'] ? "{$prefix} · {$match['points']} pts" : "{$prefix} · sin jugar";
    }
}
```

- [ ] **Step 4: Run the tests**

Run: `herd php artisan test --compact tests/Unit/Services/ValueForecastModelTest.php`
Expected: PASS (6 tests). If `impact_pct` comparisons fail on `-0.35` vs `-0.35000000000000003`, the `round(…, 2)` in
`reason()` is missing.

- [ ] **Step 5: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/ValueForecast/ValueForecastModel.php app/Services/ValueForecast/ValueForecastPrediction.php tests/Unit/Services/ValueForecastModelTest.php
git commit -m "feat: predict tomorrow's value with interval, P(up) and reasons"
```

---

### Task 7: Walk-forward and the reproduction backtest (gate)

**Files:**
- Create: `app/Services/ValueForecast/ValueForecastWalkForward.php`, `app/Services/ValueForecast/ValueForecastDay.php`
- Create: `app/Console/Commands/BacktestValueForecast.php`
- Test: `tests/Feature/Services/ValueForecastWalkForwardTest.php`, `tests/Feature/Console/Commands/BacktestValueForecastTest.php`

**Interfaces:**
- Consumes: `ValueForecastFeatures::rows`, `HybridRegression`, `ValueForecastModel`, `ValueForecastFeatureVector::of/target`.
- Produces:
  - `ValueForecastDay` (readonly): `string $referenceDate, ValueForecastModel $model, int $trainingRows,
    list<ValueForecastPrediction> $predictions`; `targetDate(): string`.
  - `ValueForecastWalkForward::days(Season $season, string $firstReferenceDate, string $lastReferenceDate): Generator<int, ValueForecastDay>`
    — for each reference date `d` in range: fits on every row with `targetDate ≤ d` and a known outcome, residuals from
    rows with `targetDate` in `[d − (residualWindowDays − 1), d]`, predicts every row with `referenceDate = d`. Skips
    a date with fewer than `minimumTrainingRows` training rows or no rows to predict.
  - `ValueForecastWalkForward::predictedValues(Season, string $first, string $last): array<int, array<string, int>>`
    (player id → reference date → predicted value), for `season:backtest-max-bid` (Task 10).
  - Command `season:backtest-value-forecast {--from= : First target date} {--to= : Last target date}`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Services/ValueForecastWalkForwardTest.php`:

```php
<?php

use App\Models\Season;
use App\Services\ValueForecast\ValueForecastDay;
use App\Services\ValueForecast\ValueForecastParameters;
use App\Services\ValueForecast\ValueForecastWalkForward;

beforeEach(function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
});

test('fits only on outcomes known by the reference date and predicts that day\'s rows', function (): void {
    foreach (range(1, 4) as $index) {
        forecastPlayer($this->season, array_map(fn (int $day): int => 10_000_000 + $index * $day * 50_000, range(0, 19)), '2026-09-20');
    }

    $days = iterator_to_array(app(ValueForecastWalkForward::class)->days($this->season, '2026-09-15', '2026-09-19'), false);

    expect($days)->not->toBeEmpty()
        ->and(array_map(fn (ValueForecastDay $day): string => $day->referenceDate, $days))->toBe(['2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-19'])
        ->and($days[0]->targetDate())->toBe('2026-09-16')
        ->and($days[0]->predictions)->toHaveCount(4)
        // Rows from 2026-09-04 (T−3 = 09-01) with targets ≤ 09-15: T = 09-04 … 09-14 → 11 days × 4 players.
        ->and($days[0]->trainingRows)->toBe(44)
        ->and($days[1]->trainingRows)->toBe(48);
});

test('yields nothing while there are too few training rows', function (): void {
    forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000], '2026-09-20');

    expect(iterator_to_array(app(ValueForecastWalkForward::class)->days($this->season, '2026-09-19', '2026-09-19'), false))->toBe([]);
});

test('lists predicted values by player and reference date', function (): void {
    $player = forecastPlayer($this->season, array_map(fn (int $day): int => 10_000_000 + $day * 100_000, range(0, 19)), '2026-09-20');
    foreach (range(1, 3) as $index) {
        forecastPlayer($this->season, array_map(fn (int $day): int => 8_000_000 - $index * $day * 20_000, range(0, 19)), '2026-09-20');
    }

    $values = app(ValueForecastWalkForward::class)->predictedValues($this->season, '2026-09-18', '2026-09-19');

    expect(array_keys($values[$player->id]))->toBe(['2026-09-18', '2026-09-19'])
        ->and($values[$player->id]['2026-09-19'])->toBeInt()->toBeGreaterThan(11_800_000);
});
```

(`forecastPlayer()` with 20 values ending 2026-09-20 → dates 09-01 … 09-20; the season starts 2026-08-01 with
`warmupDays: 0`, so rows start at 09-04, the first day with T−3 available.)

`tests/Feature/Console/Commands/BacktestValueForecastTest.php`:

```php
<?php

use App\Console\Commands\BacktestValueForecast;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\ValueForecast;
use App\Services\ValueForecast\ValueForecastParameters;

test('replays the forecast day by day and reports each predictor, read-only', function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
    foreach (range(1, 4) as $index) {
        forecastPlayer($season, array_map(fn (int $day): int => 10_000_000 + ($index - 2) * $day * 40_000, range(0, 19)), '2026-09-20');
    }

    $this->artisan(BacktestValueForecast::class, ['--from' => '2026-09-16', '--to' => '2026-09-20'])
        ->expectsOutputToContain('Persistencia')
        ->expectsOutputToContain('Momentum puja')
        ->expectsOutputToContain('Híbrido')
        ->expectsOutputToContain('Calibración de P(sube)')
        ->assertSuccessful();

    expect(ValueForecast::query()->count())->toBe(0)
        ->and(PlayerMarket::query()->count())->toBe(80);
});

test('fails clearly without market history', function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);

    $this->artisan(BacktestValueForecast::class)
        ->expectsOutputToContain('No hay histórico de mercado')
        ->assertFailed();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Services/ValueForecastWalkForwardTest.php tests/Feature/Console/Commands/BacktestValueForecastTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement the day and the walk-forward**

`app/Services/ValueForecast/ValueForecastDay.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use Carbon\CarbonImmutable;

/**
 * One reference date of the walk-forward: the model fitted with what was
 * known then, and its forecast for every row of that date.
 */
final readonly class ValueForecastDay
{
    /**
     * @param  list<ValueForecastPrediction>  $predictions
     */
    public function __construct(
        public string $referenceDate,
        public ValueForecastModel $model,
        public int $trainingRows,
        public array $predictions,
    ) {}

    public function targetDate(): string
    {
        return CarbonImmutable::parse($this->referenceDate)->addDay()->toDateString();
    }
}
```

`app/Services/ValueForecast/ValueForecastWalkForward.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Models\Season;
use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Fits the model day after day with only what was known at each reference
 * date, adding each day's newly known rows to the regression once. The
 * scheduled forecast is its last day; the backtests replay many.
 */
final class ValueForecastWalkForward
{
    public function __construct(
        private readonly ValueForecastFeatures $features,
        private readonly ValueForecastParameters $parameters = new ValueForecastParameters,
    ) {}

    /**
     * @return Generator<int, ValueForecastDay>
     */
    public function days(Season $season, string $firstReferenceDate, string $lastReferenceDate): Generator
    {
        $rows = $this->features->rows($season, $lastReferenceDate);
        $byReference = [];

        foreach ($rows as $row) {
            $byReference[$row->referenceDate][] = $row;
        }

        $training = array_values(array_filter($rows, fn (ValueForecastRow $row): bool => $row->nextValue !== null));
        usort($training, fn (ValueForecastRow $a, ValueForecastRow $b): int => strcmp($a->targetDate, $b->targetDate));
        $regression = new HybridRegression(Vector::SIZE, $this->parameters->lambda);
        $added = 0;

        for ($day = CarbonImmutable::parse($firstReferenceDate); $day->toDateString() <= $lastReferenceDate; $day = $day->addDay()) {
            $reference = $day->toDateString();

            while ($added < count($training) && $training[$added]->targetDate <= $reference) {
                $row = $training[$added++];
                $regression->add(Vector::of($row), Vector::target($row, $this->parameters));
            }

            if ($regression->count() < $this->parameters->minimumTrainingRows || !isset($byReference[$reference])) {
                continue;
            }

            $windowStart = $day->subDays($this->parameters->residualWindowDays - 1)->toDateString();
            $window = [];

            for ($index = $added - 1; $index >= 0 && $training[$index]->targetDate >= $windowStart; $index--) {
                $window[] = $training[$index];
            }

            $model = (new ValueForecastModel($regression->solve(), $this->parameters))->withResiduals($window);

            yield new ValueForecastDay(
                $reference,
                $model,
                $regression->count(),
                array_map(fn (ValueForecastRow $row): ValueForecastPrediction => $model->predict($row), $byReference[$reference]),
            );
        }
    }

    /**
     * @return array<int, array<string, int>> player id → reference date → predicted value of the next day
     */
    public function predictedValues(Season $season, string $firstReferenceDate, string $lastReferenceDate): array
    {
        $values = [];

        foreach ($this->days($season, $firstReferenceDate, $lastReferenceDate) as $day) {
            foreach ($day->predictions as $prediction) {
                $values[$prediction->row->playerId][$day->referenceDate] = $prediction->predictedValue();
            }
        }

        return $values;
    }
}
```

- [ ] **Step 4: Implement the backtest command** — `app/Console/Commands/BacktestValueForecast.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\ValueForecast\ValueForecastParameters;
use App\Services\ValueForecast\ValueForecastPrediction;
use App\Services\ValueForecast\ValueForecastRow;
use App\Services\ValueForecast\ValueForecastWalkForward;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:backtest-value-forecast {--from= : First target date (Y-m-d)} {--to= : Last target date (Y-m-d)}')]
#[Description('Replay the value forecast walk-forward over the market history and compare it with persistence and the max bid momentum (writes nothing)')]
class BacktestValueForecast extends Command
{
    /** Day-1 momentum of the max bid without adjustments: (v − v₋₃) / 3 × 0,9. */
    private const float MOMENTUM_DECAY = 0.9;

    public function handle(ValueForecastWalkForward $walkForward, ValueForecastParameters $parameters): int
    {
        $season = Season::current();
        $latest = PlayerMarket::query()->max('date');

        if ($latest === null) {
            $this->error('No hay histórico de mercado que reproducir.');

            return self::FAILURE;
        }

        $toOption = $this->option('to');
        $fromOption = $this->option('from');
        $lastTarget = is_string($toOption) ? CarbonImmutable::parse($toOption) : CarbonImmutable::parse(substr((string) $latest, 0, 10));
        $firstTarget = is_string($fromOption)
            ? CarbonImmutable::parse($fromOption)
            : $season->start_date->addDays($parameters->warmupDays + $parameters->residualWindowDays);

        /** @var array<string, list<array{row: ValueForecastRow, pred: float, low: float|null, high: float|null}>> $results */
        $results = ['Persistencia' => [], 'Momentum puja máx.' => [], 'Híbrido' => []];

        /** @var array<int, list<int>> $calibration decile → outcomes (1 = rose) */
        $calibration = [];

        foreach ($walkForward->days($season, $firstTarget->subDay()->toDateString(), $lastTarget->subDay()->toDateString()) as $day) {
            foreach ($day->predictions as $prediction) {
                $row = $prediction->row;
                $actual = $row->actualChange();

                if ($actual === null) {
                    continue;
                }

                $results['Persistencia'][] = ['row' => $row, 'pred' => $row->changeToday, 'low' => null, 'high' => null];
                $results['Momentum puja máx.'][] = ['row' => $row, 'pred' => $this->momentum($row), 'low' => null, 'high' => null];
                $results['Híbrido'][] = ['row' => $row, 'pred' => $prediction->change, 'low' => $prediction->lowChange, 'high' => $prediction->highChange];
                $calibration[min(9, (int) floor($prediction->upProbability * 10))][] = $actual > 0 ? 1 : 0;
            }
        }

        if ($results['Híbrido'] === []) {
            $this->info('No hay previsiones que evaluar en ese periodo.');

            return self::SUCCESS;
        }

        $segments = [
            "Todo ({$firstTarget->toDateString()} a {$lastTarget->toDateString()})" => fn (ValueForecastRow $row): bool => true,
            'Mañana = D+2 de un partido del equipo' => fn (ValueForecastRow $row): bool => $row->matchYesterday['team'],
            'Sin partido del equipo ayer' => fn (ValueForecastRow $row): bool => !$row->matchYesterday['team'],
            'Valor ≥ 5 M' => fn (ValueForecastRow $row): bool => $row->value >= 5_000_000,
            'Giros (mañana cambia de signo)' => fn (ValueForecastRow $row): bool => ($row->actualChange() > 0) !== ($row->changeToday > 0),
        ];

        foreach ($segments as $title => $filter) {
            $this->newLine();
            $this->info($title);
            $this->table(
                ['Predictor', 'n', 'Signo', '3 clases', 'Error medio €', 'Error mediano €', 'Error pp', 'Cobertura 80 %'],
                array_map(fn (string $name): array => $this->metricsRow($name, array_values(array_filter($results[$name], fn (array $entry): bool => $filter($entry['row']))), $parameters), array_keys($results)),
            );
        }

        ksort($calibration);
        $this->newLine();
        $this->info('Calibración de P(sube) del híbrido');
        $this->table(['Predicho', 'Real', 'n'], array_map(
            fn (int $decile, array $outcomes): array => [($decile * 10).'–'.($decile * 10 + 10).' %', $this->percent(array_sum($outcomes) / count($outcomes)), count($outcomes)],
            array_keys($calibration),
            $calibration,
        ));

        return self::SUCCESS;
    }

    /** The max bid's day-1 increment as a fraction of today's value, from the row's last three changes. */
    private function momentum(ValueForecastRow $row): float
    {
        $valueThreeDaysAgo = $row->value / ((1 + $row->changeToday) * (1 + $row->changeYesterday) * (1 + $row->changeBefore));

        return ($row->value - $valueThreeDaysAgo) / 3 * self::MOMENTUM_DECAY / $row->value;
    }

    /**
     * @param  list<array{row: ValueForecastRow, pred: float, low: float|null, high: float|null}>  $entries
     * @return list<string|int>
     */
    private function metricsRow(string $name, array $entries, ValueForecastParameters $parameters): array
    {
        if ($entries === []) {
            return [$name, 0, '—', '—', '—', '—', '—', '—'];
        }

        $class = fn (float $change): int => $change > $parameters->stableBand ? 1 : ($change < -$parameters->stableBand ? -1 : 0);
        $sign = 0;
        $classes = 0;
        $errorsEuro = [];
        $errorPp = 0.0;
        $covered = 0;
        $withInterval = 0;

        foreach ($entries as ['row' => $row, 'pred' => $pred, 'low' => $low, 'high' => $high]) {
            $actual = (float) $row->actualChange();
            $sign += ($pred > 0) === ($actual > 0) ? 1 : 0;
            $classes += $class($pred) === $class($actual) ? 1 : 0;
            $errorsEuro[] = abs($row->value * (1 + $pred) - (int) $row->nextValue);
            $errorPp += abs($pred - $actual) * 100;

            if ($low !== null && $high !== null) {
                $withInterval++;
                $covered += $actual >= $low && $actual <= $high ? 1 : 0;
            }
        }

        sort($errorsEuro);
        $count = count($entries);

        return [
            $name,
            $count,
            $this->percent($sign / $count),
            $this->percent($classes / $count),
            number_format(array_sum($errorsEuro) / $count, 0, ',', '.'),
            number_format($errorsEuro[intdiv($count, 2)], 0, ',', '.'),
            number_format($errorPp / $count, 2, ',', '.'),
            $withInterval === 0 ? '—' : $this->percent($covered / $withInterval),
        ];
    }

    private function percent(float $share): string
    {
        return number_format($share * 100, 1, ',', '.').' %';
    }
}
```

- [ ] **Step 5: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Services/ValueForecastWalkForwardTest.php tests/Feature/Console/Commands/BacktestValueForecastTest.php`
Expected: PASS.

- [ ] **Step 6: Reproduction gate (local DB, read-only)**

Run: `herd php artisan season:backtest-value-forecast --from=2026-08-16 --to=2026-09-29`
Expected, "Todo" table, row "Híbrido": Signo **95,2 % ± 0,5 pp**, Error medio ≈ **44.146 € ± 3 %**, Cobertura 80 %
≈ **81 % ± 2 pp**; "Persistencia" ≈ 94,3 % / 46.763 €. n ≈ 22 986 (it can differ slightly if the local DB gained
rows since 2026-09-29; the period is fixed so it should not).
If the hybrid is off by more than the tolerance, **stop**: compare with `backtest.js` (feature order, bucket edges,
`targetClip`, residual window, the `referenceDate < season.start + 13 d` cut, market mean over all players) before
going on. Paste the "Todo" table into the task report.

- [ ] **Step 7: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/ValueForecast/ValueForecastWalkForward.php app/Services/ValueForecast/ValueForecastDay.php app/Console/Commands/BacktestValueForecast.php tests/Feature/Services/ValueForecastWalkForwardTest.php tests/Feature/Console/Commands/BacktestValueForecastTest.php
git commit -m "feat: walk the value forecast forward and backtest it"
```

---

### Task 8: Scheduled forecast with an input fingerprint

**Files:**
- Create: `app/Services/ValueForecast/ValueForecastFingerprint.php`, `app/Services/ValueForecast/ValueForecastWriter.php`
- Create: `app/Console/Commands/ForecastValues.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Feature/Console/Commands/ForecastValuesTest.php`

**Interfaces:**
- Consumes: `ValueForecastWalkForward::days`, `ValueForecastDay`, `ValueForecast`, `ValueForecastFit`.
- Produces: `ValueForecastFingerprint::for(Season, string $referenceDate): string`;
  `ValueForecastWriter::write(Season, ValueForecastDay, string $inputsHash): int` (rows written);
  command `season:forecast-values {--force}`; `value_forecasts` rows keyed by `(season_id, player_id, target_date)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Console\Commands\ForecastValues;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Season;
use App\Models\Team;
use App\Models\ValueForecast;
use App\Models\ValueForecastFit;
use App\Services\ValueForecast\ValueForecastParameters;

beforeEach(function (): void {
    $this->travelTo('2026-09-20 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
    $this->players = collect(range(1, 4))->map(fn (int $index) => forecastPlayer(
        $this->season,
        array_map(fn (int $day): int => 10_000_000 + ($index - 2) * $day * 40_000, range(0, 19)),
        '2026-09-20',
    ));
});

test('writes tomorrow\'s forecast of every league player and the fit of the day', function (): void {
    $this->artisan(ForecastValues::class)->assertSuccessful();

    $forecast = ValueForecast::query()->where('player_id', $this->players[0]->id)->sole();
    $fit = ValueForecastFit::query()->sole();

    expect(ValueForecast::query()->count())->toBe(4)
        ->and($forecast->reference_date->toDateString())->toBe('2026-09-20')
        ->and($forecast->target_date->toDateString())->toBe('2026-09-21')
        ->and($forecast->value)->toBe(10_000_000 - 19 * 40_000)
        ->and($forecast->predicted_value)->toBeGreaterThan(0)
        ->and($forecast->low)->toBeLessThanOrEqual($forecast->predicted_value)
        ->and($forecast->high)->toBeGreaterThanOrEqual($forecast->predicted_value)
        ->and($forecast->reasons[0]['kind'])->toBe('inertia')
        ->and($fit->reference_date->toDateString())->toBe('2026-09-20')
        ->and($fit->inputs_hash)->not->toBe('')
        ->and($fit->coefficients)->toHaveCount(30);
});

test('does nothing when the inputs did not change, and refits when a match finishes', function (): void {
    $this->artisan(ForecastValues::class)->assertSuccessful();
    $firstWrite = ValueForecast::query()->max('updated_at');
    $this->travel(15)->minutes();

    $this->artisan(ForecastValues::class)->expectsOutputToContain('Sin cambios')->assertSuccessful();
    expect(ValueForecast::query()->max('updated_at'))->toBe($firstWrite);
    $hashBefore = ValueForecastFit::query()->sole()->inputs_hash;

    $player = $this->players[0];
    $fixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'team_local_id' => $player->team_id,
        'team_guest_id' => Team::factory()->create()->id,
        'date' => '2026-09-20 16:00:00',
        'state' => FixtureState::Finished,
    ]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $player->team_id, 'fantasy_points' => 14, 'fantasy_stats' => ['mins_played' => [90, 2]]]);

    $this->artisan(ForecastValues::class)->assertSuccessful();

    expect(ValueForecastFit::query()->sole()->inputs_hash)->not->toBe($hashBefore)
        ->and(ValueForecast::query()->max('updated_at'))->not->toBe($firstWrite);
});

test('skips out-of-league players and players of other teams, and drops their stale rows', function (): void {
    $this->artisan(ForecastValues::class)->assertSuccessful();
    $this->players[1]->update(['status' => PlayerStatus::OutOfLeague]);

    $this->artisan(ForecastValues::class, ['--force' => true])->assertSuccessful();

    expect(ValueForecast::query()->pluck('player_id')->all())->not->toContain($this->players[1]->id)
        ->and(ValueForecast::query()->count())->toBe(3);
});

test('says so and writes nothing without enough history', function (): void {
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 100_000));

    $this->artisan(ForecastValues::class)->expectsOutputToContain('Sin datos suficientes')->assertSuccessful();

    expect(ValueForecast::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/ForecastValuesTest.php`
Expected: FAIL — `Class "App\Console\Commands\ForecastValues" not found`.

- [ ] **Step 3: Implement**

`app/Services/ValueForecast/ValueForecastFingerprint.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\FixtureState;
use App\Models\Season;
use Illuminate\Support\Facades\DB;

/**
 * What the day's forecast depends on, as one hash: the reference day's
 * published values and every finished match of the season with its points.
 * It changes when the values are published and when a match finishes or
 * gets its points, the two moments the forecast must be redone.
 */
final class ValueForecastFingerprint
{
    public function for(Season $season, string $referenceDate): string
    {
        $market = DB::table('player_markets')
            ->whereDate('date', $referenceDate)
            ->selectRaw('count(*) as rows_count, coalesce(sum(value), 0) as total')
            ->first();
        $finished = DB::table('fixtures')
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished->value)
            ->count();
        $points = DB::table('fixture_lineups')
            ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
            ->where('fixtures.season_id', $season->id)
            ->where('fixtures.state', FixtureState::Finished->value)
            ->selectRaw('count(fixture_lineups.id) as rows_count, count(fixture_lineups.fantasy_points) as scored, coalesce(sum(fixture_lineups.fantasy_points), 0) as total')
            ->first();

        return sha1((string) json_encode([
            $referenceDate,
            (int) ($market->rows_count ?? 0),
            (int) ($market->total ?? 0),
            $finished,
            (int) ($points->rows_count ?? 0),
            (int) ($points->scored ?? 0),
            (int) ($points->total ?? 0),
        ]));
    }
}
```

`app/Services/ValueForecast/ValueForecastWriter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Season;
use App\Models\ValueForecast;
use App\Models\ValueForecastFit;
use Illuminate\Support\Facades\DB;

/**
 * Stores one day of the walk-forward: the forecast of every league player
 * of the season (never out-of-league ones) for the target date, replacing
 * that date's previous rows, and the fit with its input fingerprint.
 */
final class ValueForecastWriter
{
    public function write(Season $season, ValueForecastDay $day, string $inputsHash): int
    {
        $eligible = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->pluck('id')
            ->flip();
        $now = now();
        $rows = [];

        foreach ($day->predictions as $prediction) {
            if (!$eligible->has($prediction->row->playerId)) {
                continue;
            }

            $rows[] = [
                'season_id' => $season->id,
                'player_id' => $prediction->row->playerId,
                'reference_date' => $day->referenceDate,
                'target_date' => $day->targetDate(),
                'value' => $prediction->row->value,
                'predicted_value' => $prediction->predictedValue(),
                'low' => $prediction->low(),
                'high' => $prediction->high(),
                'change_pct' => round($prediction->change * 100, 4),
                'up_probability' => round($prediction->upProbability, 4),
                'reasons' => (string) json_encode($prediction->reasons),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($season, $day, $inputsHash, $rows, $now): void {
            ValueForecastFit::query()->upsert([[
                'season_id' => $season->id,
                'reference_date' => $day->referenceDate,
                'inputs_hash' => $inputsHash,
                'coefficients' => (string) json_encode($day->model->coefficients),
                'quantiles' => (string) json_encode($day->model->quantiles()),
                'metrics' => (string) json_encode(['training_rows' => $day->trainingRows, 'forecasts' => count($rows)]),
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['season_id', 'reference_date'], ['inputs_hash', 'coefficients', 'quantiles', 'metrics', 'updated_at']);

            foreach (array_chunk($rows, 500) as $chunk) {
                ValueForecast::query()->upsert(
                    $chunk,
                    ['season_id', 'player_id', 'target_date'],
                    ['reference_date', 'value', 'predicted_value', 'low', 'high', 'change_pct', 'up_probability', 'reasons', 'updated_at'],
                );
            }

            ValueForecast::query()
                ->where('season_id', $season->id)
                ->whereDate('target_date', $day->targetDate())
                ->whereNotIn('player_id', array_column($rows, 'player_id'))
                ->delete();
        });

        return count($rows);
    }
}
```

`app/Console/Commands/ForecastValues.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\ValueForecastFit;
use App\Services\ValueForecast\ValueForecastFingerprint;
use App\Services\ValueForecast\ValueForecastWalkForward;
use App\Services\ValueForecast\ValueForecastWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:forecast-values {--force : Refit even if the inputs did not change}')]
#[Description('Forecast tomorrow\'s value of every league player (god mode only) when the published values or the finished matches changed')]
class ForecastValues extends Command
{
    public function handle(ValueForecastWalkForward $walkForward, ValueForecastWriter $writer, ValueForecastFingerprint $fingerprint): int
    {
        $season = Season::current();
        $latest = PlayerMarket::query()->max('date');

        if ($latest === null) {
            $this->info('No hay valores de mercado.');

            return self::SUCCESS;
        }

        $reference = substr((string) $latest, 0, 10);
        $hash = $fingerprint->for($season, $reference);
        $unchanged = ValueForecastFit::query()
            ->where('season_id', $season->id)
            ->whereDate('reference_date', $reference)
            ->where('inputs_hash', $hash)
            ->exists();

        if ($unchanged && !$this->option('force')) {
            $this->info("Sin cambios desde la última previsión del {$reference}.");

            return self::SUCCESS;
        }

        $day = null;

        foreach ($walkForward->days($season, $reference, $reference) as $day) {
            // one day only
        }

        if ($day === null) {
            $this->info('Sin datos suficientes para ajustar el modelo.');

            return self::SUCCESS;
        }

        $written = $writer->write($season, $day, $hash);
        $this->info("{$written} previsiones para el {$day->targetDate()}.");

        return self::SUCCESS;
    }
}
```

In `bootstrap/app.php`, after `season:sync-player-markets`:

```php
        $schedule->command('season:forecast-values')
            ->everyFifteenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 4: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/ForecastValuesTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Try it on the local DB**

Run: `herd php artisan season:forecast-values` → "N previsiones para el …" (N ≈ number of league players, ~550), then
again → "Sin cambios …". Time it (`Measure-Command` / `time`): it must finish well under the 15-minute schedule (expect
seconds; if it takes more than ~60 s, profile `ValueForecastFeatures::rows`).

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/ValueForecast/ValueForecastFingerprint.php app/Services/ValueForecast/ValueForecastWriter.php app/Console/Commands/ForecastValues.php bootstrap/app.php tests/Feature/Console/Commands/ForecastValuesTest.php
git commit -m "feat: forecast tomorrow's values on a schedule when inputs change"
```

---

### Task 9: The forecast as the max bid's day 1 (variant B)

**Files:**
- Modify: `app/Services/MaxBidInputs.php`, `app/Services/MaxBidCalculator.php`, `app/Services/MaxBidEstimate.php`
- Test: `tests/Unit/Services/MaxBidFormulaTest.php`, `tests/Feature/Services/MaxBidCalculatorTest.php`

**Interfaces:**
- Consumes: `App\Models\ValueForecast` (Task 2).
- Produces: `MaxBidInputs::$dayOneForecast` (`?int`, last constructor parameter, default `null`);
  `MaxBidInputs::withDayOneForecast(?int): MaxBidInputs`; `MaxBidEstimate::$dayOneForecast`, `$dayOneOffset`
  (`?int`) and `toArray()` keys `day_one_forecast`, `day_one_offset`.

- [ ] **Step 1: Write the failing unit tests** — append to `tests/Unit/Services/MaxBidFormulaTest.php` (it already has
  `formulaInputs()`; add `use App\Enums\MaxBidStatus;` if missing):

```php
test('the value forecast becomes day 1 and shifts days 2–14 by the same amount', function (): void {
    $without = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters);
    $with = MaxBidCalculator::estimateFromInputs(formulaInputs(['dayOneForecast' => 10_150_000]), new MaxBidParameters);
    $offset = 10_150_000 - $without->projection[1];

    expect($with->projection[0])->toBe(10_000_000)
        ->and($with->projection[1])->toBe(10_150_000)
        ->and(array_map(fn (int $before, int $after): int => $after - $before, array_slice($without->projection, 1), array_slice($with->projection, 1)))
        ->toBe(array_fill(0, MaxBidCalculator::LOCK_DAYS, $offset))
        ->and($with->dayOneForecast)->toBe(10_150_000)
        ->and($with->dayOneOffset)->toBe($offset)
        ->and($with->toArray()['day_one_forecast'])->toBe(10_150_000)
        ->and($with->status)->toBe($without->status)
        ->and($with->dailyIncrement)->toBe($without->dailyIncrement)
        ->and($with->bid)->toBeGreaterThan($without->bid);
});

test('a forecast below today moves the path but never the profitability', function (): void {
    $with = MaxBidCalculator::estimateFromInputs(formulaInputs(['dayOneForecast' => 9_700_000]), new MaxBidParameters);

    expect($with->status)->toBe(MaxBidStatus::Profitable)
        ->and($with->projection[1])->toBe(9_700_000);
});

test('without a forecast the projection is the plain one', function (): void {
    $estimate = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters);

    expect($estimate->dayOneForecast)->toBeNull()
        ->and($estimate->dayOneOffset)->toBeNull()
        ->and($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, 100_000.0, (new MaxBidParameters)->incrementDecayBreak));
});

test('withDayOneForecast keeps every other input', function (): void {
    $inputs = formulaInputs(['doubtful' => true, 'referenceDate' => '2026-09-26']);
    $copy = $inputs->withDayOneForecast(10_050_000);

    expect($copy->dayOneForecast)->toBe(10_050_000)
        ->and($copy->doubtful)->toBeTrue()
        ->and($copy->referenceDate)->toBe('2026-09-26')
        ->and($copy->value)->toBe($inputs->value)
        ->and($inputs->dayOneForecast)->toBeNull();
});
```

- [ ] **Step 2: Write the failing feature tests** — append to `tests/Feature/Services/MaxBidCalculatorTest.php`
  (its `beforeEach` travels to 2026-09-26 and `maxBidPlayer()` ends the values today; add
  `use App\Models\ValueForecast;`):

```php
test('gatherInputs takes the stored forecast made on its own reference date only', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    ValueForecast::factory()->create([
        'season_id' => $this->season->id,
        'player_id' => $player->id,
        'reference_date' => '2026-09-25',
        'target_date' => '2026-09-26',
        'predicted_value' => 1,
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->dayOneForecast)->toBeNull();

    ValueForecast::factory()->create([
        'season_id' => $this->season->id,
        'player_id' => $player->id,
        'reference_date' => '2026-09-26',
        'target_date' => '2026-09-27',
        'predicted_value' => 10_420_000,
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->dayOneForecast)->toBe(10_420_000)
        ->and(app(MaxBidCalculator::class)->estimate($player, $this->season)->projection[1])->toBe(10_420_000);
});

test('an unavailable player never reads the forecast', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => PlayerStatus::Injured]);
    ValueForecast::factory()->create([
        'season_id' => $this->season->id,
        'player_id' => $player->id,
        'reference_date' => '2026-09-26',
        'target_date' => '2026-09-27',
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->dayOneForecast)->toBeNull();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `herd php artisan test --compact tests/Unit/Services/MaxBidFormulaTest.php tests/Feature/Services/MaxBidCalculatorTest.php`
Expected: FAIL — `Unknown named parameter $dayOneForecast` / undefined property.

- [ ] **Step 4: Implement**

`MaxBidInputs` — last constructor parameter and a copier:

```php
        public ?float $nextStartProbability = null,
        /**
         * The value forecast for the day after `referenceDate`, made on that
         * same date (value forecast spec §4.1): the projection's day 1, with
         * days 2–14 shifted by the same amount. Null without one.
         */
        public ?int $dayOneForecast = null,
    ) {}

    /** A copy with another day-1 forecast (the backtest injects its walk-forward one). */
    public function withDayOneForecast(?int $dayOneForecast): self
    {
        return new self(...[...get_object_vars($this), 'dayOneForecast' => $dayOneForecast]);
    }
```

`MaxBidEstimate` — two constructor parameters after `referenceDate` and two `toArray()` keys:

```php
        public ?string $referenceDate = null,
        /** The value forecast used as day 1, null without one. */
        public ?int $dayOneForecast = null,
        /** How much the forecast moved the projection (forecast − the formula's own day 1), null without one. */
        public ?int $dayOneOffset = null,
    ) {}
```

```php
            'reference_date' => $this->referenceDate,
            'day_one_forecast' => $this->dayOneForecast,
            'day_one_offset' => $this->dayOneOffset,
```

`MaxBidCalculator`:
- add `use App\Models\ValueForecast;`;
- in `gatherInputs()`'s final `return new MaxBidInputs(...)` add
  `dayOneForecast: $this->dayOneForecast($player, $season, $referenceDate),` after `nextStartProbability:`;
- in `estimateFromInputs()` replace `$projection = self::project($value, $increment, $decay);` with:

```php
        $projection = self::project($value, $increment, $decay);
        $dayOneOffset = null;

        if ($inputs->dayOneForecast !== null) {
            $dayOneOffset = $inputs->dayOneForecast - $projection[1];

            for ($day = 1; $day <= self::LOCK_DAYS; $day++) {
                $projection[$day] += $dayOneOffset;
            }
        }
```

  and pass `dayOneForecast: $inputs->dayOneForecast, dayOneOffset: $dayOneOffset,` to the returned `MaxBidEstimate`
  (after `referenceDate:`);
- add the reader:

```php
    /**
     * The stored value forecast for the day after `$referenceDate`, made on
     * that same reference date; null without one — an older forecast never
     * counts (value forecast spec §4.1).
     */
    private function dayOneForecast(Player $player, Season $season, string $referenceDate): ?int
    {
        $predictedValue = ValueForecast::query()
            ->where('season_id', $season->id)
            ->where('player_id', $player->id)
            ->whereDate('reference_date', $referenceDate)
            ->whereDate('target_date', CarbonImmutable::parse($referenceDate)->addDay())
            ->value('predicted_value');

        return $predictedValue === null ? null : (int) $predictedValue;
    }
```

Also update the class docblock of `MaxBidCalculator` with one line: "Day 1 of the projection is the value forecast when
there is one for the reference date (docs/superpowers/specs/2026-09-30-value-forecast-design.md §4.1)."

- [ ] **Step 5: Run the tests**

Run: `herd php artisan test --compact tests/Unit/Services tests/Feature/Services/MaxBidCalculatorTest.php tests/Feature/Http/Controllers/PlayersControllerTest.php`
Expected: PASS (the existing max bid tests are unaffected: without a stored forecast nothing changes).

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/MaxBidInputs.php app/Services/MaxBidCalculator.php app/Services/MaxBidEstimate.php tests/Unit/Services/MaxBidFormulaTest.php tests/Feature/Services/MaxBidCalculatorTest.php
git commit -m "feat: use tomorrow's value forecast as the max bid's day 1"
```

---

### Task 10: `season:backtest-max-bid` with the walk-forward day 1

**Files:**
- Modify: `app/Console/Commands/BacktestMaxBid.php`
- Test: `tests/Feature/Console/Commands/BacktestMaxBidTest.php`

**Interfaces:**
- Consumes: `ValueForecastWalkForward::predictedValues(Season, string, string): array<int, array<string, int>>`,
  `MaxBidInputs::withDayOneForecast`.
- Produces: option `--without-forecast`; private `inputs(MaxBidCalculator, Player, Season, CarbonImmutable, ?array): MaxBidInputs`
  (reused by `--calibrate` in Task 12); output lines `Previsión día 1: N de M estimaciones.` /
  `Previsión día 1: desactivada.` and `Error de la puja frente a la ideal: media X %, mediana Y % (N pujas).`

- [ ] **Step 1: Write the failing test** — append to `BacktestMaxBidTest.php` (add
  `use App\Services\ValueForecast\ValueForecastParameters;`):

```php
test('uses the walk-forward forecast as day 1 unless told not to, and reports the error against the ideal bid', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $riser = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $faller = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    foreach (range(0, 24) as $day) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString();
        PlayerMarket::factory()->create(['player_id' => $riser->id, 'date' => $date, 'value' => 10_000_000 + $day * 200_000]);
        PlayerMarket::factory()->create(['player_id' => $faller->id, 'date' => $date, 'value' => 10_000_000 - $day * 200_000]);
    }

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-10', '--to' => '2026-09-10'])
        ->expectsOutputToContain('Previsión día 1: 2 de 2 estimaciones')
        ->expectsOutputToContain('Error de la puja frente a la ideal')
        ->assertSuccessful();

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-10', '--to' => '2026-09-10', '--without-forecast' => true])
        ->expectsOutputToContain('Previsión día 1: desactivada')
        ->assertSuccessful();
});
```

(Training rows at reference 2026-09-10: targets ≤ 09-10 from rows starting 09-04 → 6 days × 2 players = 12 ≥ 10.)

- [ ] **Step 2: Run test to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/BacktestMaxBidTest.php`
Expected: FAIL — `The "--without-forecast" option does not exist.`

- [ ] **Step 3: Implement**

1. Signature: append `{--without-forecast : Replay without the value forecast as day 1 (the model before it)}`.
2. `handle(MaxBidCalculator $calculator, ValueForecastWalkForward $walkForward): int` (import
   `App\Services\ValueForecast\ValueForecastWalkForward`). Right after `$valuesByPlayer` is filled:

```php
        // Walk-forward forecasts: what the scheduled forecast would have said on
        // each reference date, with only what was known then (spec §4.1).
        $dayOneForecasts = $this->option('without-forecast')
            ? null
            : $walkForward->predictedValues($season, $from->toDateString(), $to->toDateString());
```

3. Pass `$dayOneForecasts` as a new last argument to `gridSearch(...)` (signature gains `?array $dayOneForecasts`,
   documented `@param array<int, array<string, int>>|null $dayOneForecasts`) and replace its
   `$inputs = $calculator->gatherInputs($player, $season, $day);` with
   `$inputs = $this->inputs($calculator, $player, $season, $day, $dayOneForecasts);`.
4. In the normal loop replace `$estimate = $calculator->estimate($player, $season, $day);` with:

```php
                $inputs = $this->inputs($calculator, $player, $season, $day, $dayOneForecasts);
                $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters);
```

   and, right after the `if (!in_array($estimate->status, …)) { continue; }` guard:

```php
                $estimates++;
                $withForecast += $inputs->dayOneForecast !== null ? 1 : 0;

                if ($estimate->bid !== null) {
                    $bidErrors[] = abs($estimate->bid - MaxBidCalculator::solveBid($actual, $estimate->confidence)) / max($estimate->value, 1);
                }
```

   Initialise `$estimates = 0; $withForecast = 0; /** @var list<float> $bidErrors */ $bidErrors = [];` before the loop,
   and after the first `$this->table(...)`:

```php
        $this->info($dayOneForecasts === null
            ? 'Previsión día 1: desactivada.'
            : "Previsión día 1: {$withForecast} de {$estimates} estimaciones.");
        $this->info($bidErrors === []
            ? 'Error de la puja frente a la ideal: sin pujas.'
            : sprintf(
                'Error de la puja frente a la ideal: media %s, mediana %s (%d pujas).',
                $this->percent(array_sum($bidErrors) / count($bidErrors)),
                $this->percent($this->median($bidErrors)),
                count($bidErrors),
            ));
```

5. The shared helper:

```php
    /**
     * One player-day's inputs with the walk-forward forecast of its reference
     * date as day 1 — or none with --without-forecast. A forecast stored in
     * the database is always replaced, so a replay never sees the future.
     *
     * @param  array<int, array<string, int>>|null  $dayOneForecasts  player id → reference date → predicted value
     */
    private function inputs(MaxBidCalculator $calculator, Player $player, Season $season, CarbonImmutable $day, ?array $dayOneForecasts): MaxBidInputs
    {
        $inputs = $calculator->gatherInputs($player, $season, $day);

        return $inputs->withDayOneForecast(
            $dayOneForecasts === null || $inputs->referenceDate === null ? null : ($dayOneForecasts[$player->id][$inputs->referenceDate] ?? null),
        );
    }
```

(`percent()` and `median()` already exist in the command; check `percent()` accepts a fraction — it prints the bid
error as a percentage of the value.)

- [ ] **Step 4: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/BacktestMaxBidTest.php`
Expected: PASS (all, including the existing two).

- [ ] **Step 5: Compare with the research (local DB, read-only)**

Run both:
`herd php artisan season:backtest-max-bid --from=2026-08-15 --to=2026-09-15 --without-forecast`
`herd php artisan season:backtest-max-bid --from=2026-08-15 --to=2026-09-15`
Expected (`backtest-maxbid-day1.md`): error against the ideal bid ≈ **23,12 % → 22,52 %** (± 0,5 pp each), "Prob. real
media" ≈ 57,8 % in both. Paste both summaries into the task report. A large gap means the walk-forward or the
re-anchoring differs from the research: stop and compare with `maxbid_day1.js` (variant B).

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Console/Commands/BacktestMaxBid.php tests/Feature/Console/Commands/BacktestMaxBidTest.php
git commit -m "feat: backtest the max bid with the walk-forward day-1 forecast"
```

---

### Task 11: Confidence calibration and increment shrink in `MaxBidParameters` (no behaviour change yet)

**Files:**
- Modify: `app/Services/MaxBidParameters.php`, `app/Services/MaxBidCalculator.php`
- Modify: `tests/Pest.php` (add `calibrationKnots()`)
- Test: `tests/Unit/Services/MaxBidParametersTest.php` (create), `tests/Unit/Services/MaxBidFormulaTest.php`

**Interfaces:**
- Produces (explicit `MaxBidParameters` changes):
  - `public array $confidenceCalibration = []` — `array<int, float>`: chosen confidence in whole percent (keys exactly
    50, 55, …, 95) → confidence the bid is solved at; values in (0, 1), non-decreasing. `[]` = identity.
  - `public float $incrementShrink = 1.0` — factor in (0, 1] on the daily increment before projecting (the reported
    `dailyIncrement`, the factors and the profitability are unchanged; the day-1 forecast still re-anchors).
  - `effectiveConfidence(float $confidence): float` — linear interpolation between knots, the chosen confidence
    clamped to 50–95 %.
- `MaxBidCalculator::estimateFromInputs()` projects `increment × incrementShrink` and solves the bid at
  `effectiveConfidence($confidence)`; `MaxBidEstimate::$confidence` stays the chosen one (the stepper is unchanged).

- [ ] **Step 1: Add the helper to `tests/Pest.php`**

```php
/**
 * Max bid calibration knots (50…95 %) shifted up by `$shift`, capped at 0,99.
 *
 * @return array<int, float>
 */
function calibrationKnots(float $shift): array
{
    $knots = [];

    foreach (range(50, 95, 5) as $percent) {
        $knots[$percent] = round(min(0.99, $percent / 100 + $shift), 4);
    }

    return $knots;
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Unit/Services/MaxBidParametersTest.php`:

```php
<?php

use App\Services\MaxBidParameters;

test('without a calibration the chosen confidence is used as is', function (): void {
    expect((new MaxBidParameters)->effectiveConfidence(0.75))->toBe(0.75);
});

test('interpolates between the knots and clamps to 50–95 %', function (): void {
    $parameters = new MaxBidParameters(confidenceCalibration: calibrationKnots(0.15));

    expect($parameters->effectiveConfidence(0.75))->toBe(0.9)
        ->and($parameters->effectiveConfidence(0.775))->toEqualWithDelta(0.925, 1e-9)
        ->and($parameters->effectiveConfidence(0.95))->toBe(0.99)
        ->and($parameters->effectiveConfidence(0.4))->toBe(0.65);
});

test('rejects an incomplete, out-of-range or decreasing calibration and a bad shrink', function (array $arguments): void {
    expect(fn () => new MaxBidParameters(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'missing knot' => [['confidenceCalibration' => [50 => 0.6, 95 => 0.99]]],
    'out of range' => [['confidenceCalibration' => array_replace(calibrationKnots(0.0), [95 => 1.0])]],
    'decreasing' => [['confidenceCalibration' => array_replace(calibrationKnots(0.0), [60 => 0.5])]],
    'zero shrink' => [['incrementShrink' => 0.0]],
    'shrink above one' => [['incrementShrink' => 1.2]],
]);
```

Append to `tests/Unit/Services/MaxBidFormulaTest.php`:

```php
test('a shrink scales the projected increments but not the reported factors nor the profitability', function (): void {
    $parameters = new MaxBidParameters(incrementShrink: 0.5);
    $estimate = MaxBidCalculator::estimateFromInputs(formulaInputs(), $parameters);

    expect($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, 50_000.0, $parameters->incrementDecayBreak))
        ->and($estimate->dailyIncrement)->toEqual(100_000.0)
        ->and($estimate->status)->toBe(MaxBidStatus::Profitable);
});

test('the bid is solved at the calibrated confidence while the estimate keeps the chosen one', function (): void {
    $calibrated = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: calibrationKnots(0.15)), 0.75);
    $plainAt90 = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters, 0.9);

    expect($calibrated->confidence)->toBe(0.75)
        ->and($calibrated->bid)->toBe($plainAt90->bid);
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `herd php artisan test --compact tests/Unit/Services/MaxBidParametersTest.php tests/Unit/Services/MaxBidFormulaTest.php`
Expected: FAIL — unknown named parameters / undefined method `effectiveConfidence`.

- [ ] **Step 4: Implement**

In `MaxBidParameters`, after `startProbabilityWeight`:

```php
        /**
         * Chosen confidence (whole percent, one knot every 5 from 50 to 95) →
         * the confidence the bid is solved at, so the confidence the user picks
         * is the real chance that an offer beats the bid (value forecast spec
         * §4.2; fitted with `season:backtest-max-bid --calibrate`). Empty =
         * identity.
         *
         * @var array<int, float>
         */
        public array $confidenceCalibration = [],
        /**
         * Factor on the daily increment before projecting (0 < f ≤ 1): shrinks
         * an optimistic path without touching the profitability, which only
         * depends on the increment's sign. 1 = off.
         */
        public float $incrementShrink = 1.0,
```

Add to the constructor body (after the benches check):

```php
        if ($this->incrementShrink <= 0 || $this->incrementShrink > 1) {
            throw new InvalidArgumentException('incrementShrink must be in (0, 1].');
        }

        if ($this->confidenceCalibration !== []) {
            $knots = array_keys($this->confidenceCalibration);
            sort($knots);

            if ($knots !== range(50, 95, 5)) {
                throw new InvalidArgumentException('confidenceCalibration needs exactly one knot every 5 % from 50 to 95.');
            }

            $previous = 0.0;

            foreach (range(50, 95, 5) as $percent) {
                $value = $this->confidenceCalibration[$percent];

                if ($value <= 0 || $value >= 1 || $value < $previous) {
                    throw new InvalidArgumentException('confidenceCalibration values must be in (0, 1) and never decrease.');
                }

                $previous = $value;
            }
        }
```

and the method:

```php
    /** The confidence the bid is solved at for a chosen one (0–1). */
    public function effectiveConfidence(float $confidence): float
    {
        if ($this->confidenceCalibration === []) {
            return $confidence;
        }

        $percent = max(50.0, min(95.0, $confidence * 100));
        $lower = min(90, (int) (floor($percent / 5) * 5));
        $share = ($percent - $lower) / 5;
        $from = $this->confidenceCalibration[$lower];

        return $from + $share * ($this->confidenceCalibration[$lower + 5] - $from);
    }
```

In `MaxBidCalculator::estimateFromInputs()`:
- `$projection = self::project($value, $increment * $parameters->incrementShrink, $decay);`
- `bid: $profitable ? self::solveBid($projection, $parameters->effectiveConfidence($confidence)) : null,`

- [ ] **Step 5: Run the tests**

Run: `herd php artisan test --compact tests/Unit/Services tests/Feature/Services/MaxBidCalculatorTest.php tests/Feature/Console/Commands/BacktestMaxBidTest.php`
Expected: PASS; the defaults are the identity and 1.0, so nothing else changes.

- [ ] **Step 6: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/MaxBidParameters.php app/Services/MaxBidCalculator.php tests/Pest.php tests/Unit/Services/MaxBidParametersTest.php tests/Unit/Services/MaxBidFormulaTest.php
git commit -m "feat: allow calibrating the max bid confidence and shrinking its increment"
```

---

### Task 12: `season:backtest-max-bid --calibrate` and the calibration gate

**Files:**
- Modify: `app/Console/Commands/BacktestMaxBid.php`
- Modify (only if the gate passes): `app/Services/MaxBidParameters.php` defaults, `tests/Unit/Services/MaxBidParametersTest.php`,
  `tests/Unit/Services/MaxBidFormulaTest.php`
- Test: `tests/Feature/Console/Commands/BacktestMaxBidTest.php`

**Interfaces:**
- Consumes: `inputs()` (Task 10), `MaxBidParameters(confidenceCalibration:, incrementShrink:)` (Task 11),
  `MaxBidCalculator::estimateFromInputs/solveBid/bestOfferProbabilityAtMost`.
- Produces: option `--calibrate`; output table + either `Calibración elegida (copiar en MaxBidParameters):` with the
  knots and shrink, or `Ninguna combinación cumple ±3 pp en 50/75/90 %: se mantiene la puja actual.`

- [ ] **Step 1: Write the failing tests** — append to `BacktestMaxBidTest.php`:

```php
test('fits a confidence calibration on the first half, validates it on the second and writes nothing', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);

    foreach ([150_000, 200_000, 250_000] as $pace) {
        $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

        foreach (range(0, 24) as $day) {
            PlayerMarket::factory()->create([
                'player_id' => $player->id,
                'date' => CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString(),
                'value' => 10_000_000 + $day * $pace,
            ]);
        }
    }

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-04', '--to' => '2026-09-10', '--calibrate' => true, '--without-forecast' => true])
        ->expectsOutputToContain('Calibración de la confianza')
        ->expectsOutputToContain('P real 75 %')
        ->assertSuccessful();

    expect(PlayerMarket::query()->count())->toBe(75);
});

test('needs at least two reference days to calibrate', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    foreach (range(0, 24) as $day) {
        PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString(), 'value' => 10_000_000 + $day * 100_000]);
    }

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-10', '--to' => '2026-09-10', '--calibrate' => true, '--without-forecast' => true])
        ->expectsOutputToContain('al menos dos días')
        ->assertFailed();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/BacktestMaxBidTest.php`
Expected: FAIL — `The "--calibrate" option does not exist.`

- [ ] **Step 3: Implement**

1. Signature: append `{--calibrate : Fit a confidence calibration on the first half of the dates and validate it on the second (writes nothing)}`.
2. Constants:

```php
    /** @var list<float> Increment shrinks `--calibrate` tries, each with its own fitted knots. */
    private const array CALIBRATION_SHRINKS = [1.0, 0.9, 0.8, 0.7, 0.6, 0.5];

    /** @var list<int> Confidences (%) whose realised probability must land within the tolerance. */
    private const array CALIBRATION_TARGETS = [50, 75, 90];

    private const float CALIBRATION_TOLERANCE = 0.03;

    /** Highest confidence a knot may solve at (a knot that can't reach its target gets this). */
    private const float CALIBRATION_MAX_CONFIDENCE = 0.99;
```

3. In `handle()`, right after `$dayOneForecasts` is computed and before the grid branch:

```php
        if ($this->option('calibrate')) {
            return $this->calibrate($calculator, $season, $players, $valuesByPlayer, $from, $to, $dayOneForecasts);
        }
```

4. The methods:

```php
    /**
     * Fits, for each increment shrink, the confidence knots that make the
     * realised probability (the chance the best offer of the lock beats the
     * bid, with the real path) match 50…95 % on the first half of the
     * reference dates, validates them on the second half, and picks the
     * combination within ±3 pp at 50/75/90 % with the lowest bid error
     * against the ideal bid (spec §4.2). Writes nothing.
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, array<string, int>>  $valuesByPlayer
     * @param  array<int, array<string, int>>|null  $dayOneForecasts
     */
    private function calibrate(MaxBidCalculator $calculator, Season $season, Collection $players, array $valuesByPlayer, CarbonImmutable $from, CarbonImmutable $to, ?array $dayOneForecasts): int
    {
        /** @var list<array{date: string, inputs: MaxBidInputs, actual: list<int>}> $records */
        $records = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            foreach ($players as $player) {
                $actual = $this->actualValues($valuesByPlayer[$player->id] ?? [], $day);

                if ($actual === null) {
                    continue;
                }

                $inputs = $this->inputs($calculator, $player, $season, $day, $dayOneForecasts);

                if ($inputs->presetStatus === null) {
                    $records[] = ['date' => $day->toDateString(), 'inputs' => $inputs, 'actual' => $actual];
                }
            }
        }

        $dates = array_values(array_unique(array_column($records, 'date')));

        if (count($dates) < 2) {
            $this->error('Hacen falta al menos dos días con estimaciones para ajustar y validar la calibración.');

            return self::FAILURE;
        }

        $middle = $dates[intdiv(count($dates), 2)];
        $fit = array_values(array_filter($records, fn (array $record): bool => $record['date'] < $middle));
        $validation = array_values(array_filter($records, fn (array $record): bool => $record['date'] >= $middle));

        $this->info(sprintf('Calibración de la confianza: ajuste %s a %s, validación %s a %s.', $dates[0], $fit === [] ? $dates[0] : end($fit)['date'], $middle, end($dates)));

        $baseline = $this->calibrationMetrics($validation, new MaxBidParameters);
        $rows = [$this->calibrationRow('Actual', null, $baseline)];
        $best = null;

        foreach (self::CALIBRATION_SHRINKS as $shrink) {
            $knots = $this->fitCalibration($fit, $shrink);

            if ($knots === null) {
                $rows[] = ["×{$shrink}", 'sin pujas', '—', '—', '—', '—', '—', '—', 'no'];

                continue;
            }

            $metrics = $this->calibrationMetrics($validation, new MaxBidParameters(confidenceCalibration: $knots, incrementShrink: $shrink));
            $rows[] = $this->calibrationRow("×{$shrink}", $knots, $metrics);

            if ($metrics['passes'] && ($best === null || $metrics['bidError'] < $best['metrics']['bidError'])) {
                $best = ['shrink' => $shrink, 'knots' => $knots, 'metrics' => $metrics];
            }
        }

        $this->table(
            ['Reducción', 'Conf. usada 50/75/90', 'P real 50 %', 'P real 75 %', 'P real 90 %', 'Error puja (mediana)', 'Pujas', 'Cambios rentable', 'Cumple ±3 pp'],
            $rows,
        );

        if ($best === null) {
            $this->warn('Ninguna combinación cumple ±3 pp en 50/75/90 %: se mantiene la puja actual.');

            return self::SUCCESS;
        }

        $this->info('Calibración elegida (copiar en MaxBidParameters):');
        $this->line("incrementShrink: {$best['shrink']}");
        $this->line('confidenceCalibration: ['.implode(', ', array_map(
            fn (int $percent, float $knot): string => "{$percent} => {$knot}",
            array_keys($best['knots']),
            $best['knots'],
        )).']');

        return self::SUCCESS;
    }

    /**
     * The knots (50…95 %) that make the mean realised probability of the
     * profitable estimates match each target, by scanning the confidence the
     * bid is solved at from 0,50 to 0,99 and interpolating. Null without any
     * profitable estimate.
     *
     * @param  list<array{date: string, inputs: MaxBidInputs, actual: list<int>}>  $records
     * @return array<int, float>|null
     */
    private function fitCalibration(array $records, float $shrink): ?array
    {
        $parameters = new MaxBidParameters(incrementShrink: $shrink);
        $cases = [];

        foreach ($records as $record) {
            $estimate = MaxBidCalculator::estimateFromInputs($record['inputs'], $parameters);

            if ($estimate->status === MaxBidStatus::Profitable && $estimate->projection !== null) {
                $cases[] = [$estimate->projection, $record['actual']];
            }
        }

        if ($cases === []) {
            return null;
        }

        /** @var list<array{0: float, 1: float}> $curve solved-at confidence → mean realised probability */
        $curve = [];

        for ($step = 50; $step <= 99; $step++) {
            $sum = 0.0;

            foreach ($cases as [$projection, $actual]) {
                $sum += 1 - MaxBidCalculator::bestOfferProbabilityAtMost(MaxBidCalculator::solveBid($projection, $step / 100), $actual);
            }

            $curve[] = [$step / 100, $sum / count($cases)];
        }

        $knots = [];
        $previous = 0.0;

        foreach (range(50, 95, 5) as $percent) {
            $target = $percent / 100;
            $knot = self::CALIBRATION_MAX_CONFIDENCE;

            foreach ($curve as $index => [$confidence, $realised]) {
                if ($realised < $target) {
                    continue;
                }

                if ($index === 0) {
                    $knot = $confidence;
                } else {
                    [$previousConfidence, $previousRealised] = $curve[$index - 1];
                    $knot = $previousConfidence + ($target - $previousRealised) / max($realised - $previousRealised, 1e-9) * ($confidence - $previousConfidence);
                }

                break;
            }

            $knot = round(max($previous, $knot), 4);
            $knots[$percent] = $knot;
            $previous = $knot;
        }

        return $knots;
    }

    /**
     * Realised probability at 50/75/90 %, bid error against the ideal bid at
     * 75 % (mean and median), bids and profitability changes against the
     * current defaults, on the validation records.
     *
     * @param  list<array{date: string, inputs: MaxBidInputs, actual: list<int>}>  $records
     * @return array{realised: array<int, float|null>, bidError: float, bidErrorMedian: float|null, bids: int, flips: int, passes: bool}
     */
    private function calibrationMetrics(array $records, MaxBidParameters $parameters): array
    {
        $defaults = new MaxBidParameters;
        $realised = [];

        foreach (self::CALIBRATION_TARGETS as $percent) {
            $sum = 0.0;
            $count = 0;

            foreach ($records as $record) {
                $estimate = MaxBidCalculator::estimateFromInputs($record['inputs'], $parameters, $percent / 100);

                if ($estimate->bid !== null) {
                    $sum += 1 - MaxBidCalculator::bestOfferProbabilityAtMost($estimate->bid, $record['actual']);
                    $count++;
                }
            }

            $realised[$percent] = $count === 0 ? null : $sum / $count;
        }

        $errors = [];
        $flips = 0;

        foreach ($records as $record) {
            $estimate = MaxBidCalculator::estimateFromInputs($record['inputs'], $parameters);
            $flips += $estimate->status !== MaxBidCalculator::estimateFromInputs($record['inputs'], $defaults)->status ? 1 : 0;

            if ($estimate->bid !== null) {
                $errors[] = abs($estimate->bid - MaxBidCalculator::solveBid($record['actual'], MaxBidCalculator::CONFIDENCE)) / max($estimate->value, 1);
            }
        }

        $passes = true;

        foreach ($realised as $percent => $probability) {
            $passes = $passes && $probability !== null && abs($probability - $percent / 100) <= self::CALIBRATION_TOLERANCE;
        }

        return [
            'realised' => $realised,
            'bidError' => $errors === [] ? INF : array_sum($errors) / count($errors),
            'bidErrorMedian' => $this->median($errors),
            'bids' => count($errors),
            'flips' => $flips,
            'passes' => $passes,
        ];
    }

    /**
     * @param  array<int, float>|null  $knots
     * @param  array{realised: array<int, float|null>, bidError: float, bidErrorMedian: float|null, bids: int, flips: int, passes: bool}  $metrics
     * @return list<string|int>
     */
    private function calibrationRow(string $label, ?array $knots, array $metrics): array
    {
        return [
            $label,
            $knots === null ? 'sin calibrar' : implode(' / ', array_map(fn (int $percent): string => number_format($knots[$percent] * 100, 1, ',', '.'), self::CALIBRATION_TARGETS)),
            $this->percent($metrics['realised'][50]),
            $this->percent($metrics['realised'][75]),
            $this->percent($metrics['realised'][90]),
            is_finite($metrics['bidError'])
                ? $this->percent($metrics['bidError']).' ('.$this->percent($metrics['bidErrorMedian']).')'
                : '—',
            $metrics['bids'],
            $metrics['flips'],
            $metrics['passes'] ? 'sí' : 'no',
        ];
    }
```

   (Import `App\Enums\MaxBidStatus` if the file doesn't yet. `percent(?float)` already prints `—` for null.)

- [ ] **Step 4: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/BacktestMaxBidTest.php`
Expected: PASS.

- [ ] **Step 5: Commit the tooling**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Console/Commands/BacktestMaxBid.php tests/Feature/Console/Commands/BacktestMaxBidTest.php
git commit -m "feat: fit and validate a max bid confidence calibration in the backtest"
```

- [ ] **Step 6: Run the gate on the local DB (read-only)**

Run: `herd php artisan season:backtest-max-bid --calibrate --from=2026-08-15 --to=2026-09-15`
(with the walk-forward day 1, i.e. without `--without-forecast`). It can take a few minutes. Keep the whole output for
the report.

Gate: the chosen row has "Cumple ±3 pp" = sí, i.e. validation realised probability within ±3 pp of 50 %, 75 % and
90 %. "Cambios rentable" must be 0 for every row (the calibration never touches the profitability); if not, stop — a
bug.

- [ ] **Step 7a: Gate met — make it the default**

1. In `MaxBidParameters`, set the defaults to the printed values, e.g.
   `public array $confidenceCalibration = [50 => 0.xx, 55 => …, 95 => 0.xx],` and
   `public float $incrementShrink = 0.x,`, and extend the class docblock with one sentence: "Calibrated on
   2026-08-15 → 2026-09-15 with the walk-forward day-1 forecast (`--calibrate`): realised probability at 50/75/90 %
   = a / b / c % (was 57,8 % at 75 %)."
2. Pin it — in `tests/Unit/Services/MaxBidParametersTest.php` change the first test to:

```php
test('the default calibration solves the chosen 75 % at its fitted knot', function (): void {
    $parameters = new MaxBidParameters;

    expect($parameters->effectiveConfidence(0.75))->toBe($parameters->confidenceCalibration[75])
        ->and((new MaxBidParameters(confidenceCalibration: []))->effectiveConfidence(0.75))->toBe(0.75);
});
```

3. Tests that assumed the identity: in `MaxBidFormulaTest` "the defaults reproduce a plain rising player", compare the
   projection with `MaxBidCalculator::project(10_000_000, 100_000.0 * (new MaxBidParameters)->incrementShrink, …)`;
   "without a forecast the projection is the plain one" likewise; "a shrink scales…" and "the bid is solved at the
   calibrated confidence…" pass their own parameters and stay as they are. Run
   `herd php artisan test --compact tests/Unit/Services tests/Feature/Services/MaxBidCalculatorTest.php tests/Feature/Http/Controllers/PlayersControllerTest.php tests/Feature/Console/Commands/BacktestMaxBidTest.php`
   and fix only expectations that hard-code identity numbers (never loosen a behavioural assertion).
4. Re-run `herd php artisan season:backtest-max-bid --from=2026-08-15 --to=2026-09-15`: "Prob. real media" should now be
   ≈ 75 %. Commit:

```bash
git add app/Services/MaxBidParameters.php tests/Unit/Services/MaxBidParametersTest.php tests/Unit/Services/MaxBidFormulaTest.php
git commit -m "feat: calibrate the max bid confidence so the chosen one is honest"
```

- [ ] **Step 7b: Gate not met — keep the current behaviour**

Change nothing in `MaxBidParameters` (defaults stay `[]` and `1.0`). Report to the user: the full `--calibrate` table
(realised 50/75/90 per shrink, bid error before/after, bids, flips) and the closest combination. The user decides.

---

### Task 13: Privacy guard

**Files:**
- Modify: `tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`, `tests/Feature/Http/Controllers/Api/GodPrivacyGuardTest.php`

**Interfaces:**
- Consumes: `ValueForecast`, `PlayerDailySignal` factories; `ApiWorld::seed()` (`seasonId`, `ownedPlayerId`).

This task pins a rule rather than adding behaviour: the tests pass as soon as they are written (nothing in `/api` reads
the new tables). Make them fail once on purpose to prove they bite: temporarily add `'predicted_value' => 1` to the
array returned by `App\Http\Resources\PlayerResource::toArray()`, run, see the failure, revert.

- [ ] **Step 1: Extend the max bid guard** — in `MaxBidGuardTest.php` add `'day_one_forecast', 'day_one_offset'` to
  `$forbidden`.

- [ ] **Step 2: Add the forecast guard** — append to `GodPrivacyGuardTest.php` (add `use App\Models\PlayerDailySignal;`
  and `use App\Models\ValueForecast;`):

```php
test('no api response ever carries the private value forecast or the daily signals', function (): void {
    $forbidden = [
        'predicted_value', 'up_probability', 'change_pct', 'impact_pct', 'target_date', 'day_one_forecast',
        'day_one_offset', 'value_forecast', 'valueForecast', 'forecast', 'next_difficulty',
    ];
    $privateForecast = 987_650_001;

    $world = ApiWorld::seed();
    ValueForecast::factory()->create([
        'season_id' => $world->seasonId,
        'player_id' => $world->ownedPlayerId,
        'reference_date' => now()->toDateString(),
        'target_date' => now()->addDay()->toDateString(),
        'predicted_value' => $privateForecast,
        'low' => $privateForecast - 1,
        'high' => $privateForecast + 1,
    ]);
    PlayerDailySignal::factory()->create([
        'season_id' => $world->seasonId,
        'player_id' => $world->ownedPlayerId,
        'next_difficulty' => 9.87,
    ]);
    $sampleIds = [
        'seasonManager' => $world->managerId,
        'fixture' => $world->finishedFixtureId,
        'player' => $world->ownedPlayerId,
    ];

    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/') && in_array('GET', $route->methods(), true));

    foreach ($apiRoutes as $route) {
        $url = '/'.preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $match): string => (string) ($sampleIds[$match[1]] ?? throw new RuntimeException("No sample id for route parameter {$match[1]}")),
            $route->uri(),
        );

        $response = $this->getJson($url);

        $response->assertOk();
        expect(array_values(array_intersect(godPrivacyKeys($response->json()), $forbidden)))
            ->toBe([], "{$url} exposes a private value forecast field")
            ->and($response->getContent())
            ->not->toContain((string) $privateForecast, "{$url} leaks a forecast value");
    }
});

test('the api docs never describe the value forecast', function (): void {
    $docs = mb_strtolower((string) file_get_contents(resource_path('docs/api-docs.md')));

    foreach (['previsión del valor', 'prevision del valor', 'value forecast', 'value_forecast', 'forecast'] as $term) {
        expect(str_contains($docs, $term))->toBeFalse("api-docs.md mentions {$term}");
    }
});
```

- [ ] **Step 3: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php tests/Feature/Http/Controllers/Api/GodPrivacyGuardTest.php`
Expected: PASS (and FAIL once with the temporary `PlayerResource` change, then PASS again after reverting it).

- [ ] **Step 4: Commit**

```bash
herd php vendor/bin/pint --dirty --format agent
git add tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php tests/Feature/Http/Controllers/Api/GodPrivacyGuardTest.php
git commit -m "test: guard the value forecast out of the public api"
```

---

### Task 14: God-only `valueForecast` on the ficha

**Files:**
- Create: `app/Services/ValueForecast/ValueForecastPresenter.php`
- Modify: `app/Http/Controllers/PlayersController.php`
- Test: `tests/Feature/Http/Controllers/PlayersControllerTest.php`

**Interfaces:**
- Consumes: `ValueForecast`, `PlayerMarket`, `MarketTrend::fromDailyValues(list<int>): ?MarketTrend`, `HandleGodMode::isEnabled`.
- Produces: `ValueForecastPresenter::forPlayer(Player, Season): ?array` with the shape
  `{reference_date: string, target_date: string, value: int, predicted_value: int, change: int, change_pct: float,
  low: int, high: int, up_probability: float, direction: 'up'|'stable'|'down', trend: string|null,
  reasons: list<{kind, label, impact_pct}>}`; Inertia prop `valueForecast` (that shape or `null`).

- [ ] **Step 1: Write the failing tests** — append to `PlayersControllerTest.php` (add `use App\Models\ValueForecast;`):

```php
/**
 * A god-mode ficha player with six daily values ending today (10,0 → 10,5 M€)
 * and a forecast made today for tomorrow (10,65 M€).
 *
 * @return array{0: Player, 1: Season}
 */
function forecastFichaPlayer(string $referenceDate): array
{
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);

    foreach ([10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000] as $index => $value) {
        PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(5 - $index)->toDateString(), 'value' => $value]);
    }

    ValueForecast::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'reference_date' => $referenceDate,
        'target_date' => CarbonImmutable::parse($referenceDate)->addDay()->toDateString(),
        'value' => 10_500_000,
        'predicted_value' => 10_650_000,
        'low' => 10_600_000,
        'high' => 10_700_000,
        'change_pct' => 1.4286,
        'up_probability' => 0.97,
        'reasons' => [['kind' => 'inertia', 'label' => 'Inercia: cambio de hoy', 'impact_pct' => 0.97]],
    ]);

    return [$player, $season];
}

test('the ficha has no value forecast without god mode', function (): void {
    [$player] = forecastFichaPlayer(now()->toDateString());

    $this->get(route('players.show', $player))
        ->assertOk()
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('valueForecast', null));
});

test('god mode adds today\'s value forecast with its trend and reasons', function (): void {
    [$player] = forecastFichaPlayer(now()->toDateString());
    $trend = MarketTrend::fromDailyValues([10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000, 10_650_000]);

    $this->withCookie('god_mode', '1')
        ->get(route('players.show', $player))
        ->assertOk()
        ->assertInertia(fn (Assert $page): AssertableInertia => $page
            ->where('valueForecast.target_date', now()->addDay()->toDateString())
            ->where('valueForecast.predicted_value', 10_650_000)
            ->where('valueForecast.change', 150_000)
            ->where('valueForecast.change_pct', 1.43)
            ->where('valueForecast.low', 10_600_000)
            ->where('valueForecast.up_probability', 0.97)
            ->where('valueForecast.direction', 'up')
            ->where('valueForecast.trend', $trend?->value)
            ->where('valueForecast.reasons.0.kind', 'inertia'));
});

test('a forecast made before the latest market day is not shown', function (): void {
    [$player] = forecastFichaPlayer(now()->subDay()->toDateString());

    $this->withCookie('god_mode', '1')
        ->get(route('players.show', $player))
        ->assertOk()
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('valueForecast', null));
});
```

(Add `use Carbon\CarbonImmutable;` to the test file if missing.)

- [ ] **Step 2: Run tests to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/PlayersControllerTest.php --filter="value forecast|forecast made before"`
Expected: FAIL — `valueForecast` prop missing.

- [ ] **Step 3: Implement**

`app/Services/ValueForecast/ValueForecastPresenter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\MarketTrend;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\ValueForecast;

/**
 * A player's forecast for the ficha's god «Mercado» section: only the one
 * made on the latest published market day (an older one would mix
 * yesterday's forecast with today's value), with the market trend its value
 * would draw. God mode only — the caller checks it.
 */
final class ValueForecastPresenter
{
    /** Values before the forecast one that the market trend reads (it needs seven). */
    private const int TREND_VALUES = 6;

    public function __construct(private readonly ValueForecastParameters $parameters = new ValueForecastParameters) {}

    /**
     * @return array{reference_date: string, target_date: string, value: int, predicted_value: int, change: int, change_pct: float, low: int, high: int, up_probability: float, direction: 'up'|'stable'|'down', trend: string|null, reasons: list<array{kind: string, label: string, impact_pct: float}>}|null
     */
    public function forPlayer(Player $player, Season $season): ?array
    {
        $latest = PlayerMarket::query()->max('date');

        if ($latest === null) {
            return null;
        }

        $reference = substr((string) $latest, 0, 10);
        $forecast = ValueForecast::query()
            ->where('season_id', $season->id)
            ->where('player_id', $player->id)
            ->whereDate('reference_date', $reference)
            ->first();

        if ($forecast === null) {
            return null;
        }

        $recent = PlayerMarket::query()
            ->where('player_id', $player->id)
            ->whereDate('date', '<=', $reference)
            ->orderByDesc('date')
            ->limit(self::TREND_VALUES)
            ->pluck('value')
            ->reverse()
            ->map(fn (mixed $value): int => (int) $value)
            ->values()
            ->all();
        $change = $forecast->change_pct / 100;

        return [
            'reference_date' => $forecast->reference_date->toDateString(),
            'target_date' => $forecast->target_date->toDateString(),
            'value' => $forecast->value,
            'predicted_value' => $forecast->predicted_value,
            'change' => $forecast->predicted_value - $forecast->value,
            'change_pct' => round($forecast->change_pct, 2),
            'low' => $forecast->low,
            'high' => $forecast->high,
            'up_probability' => $forecast->up_probability,
            'direction' => match (true) {
                $change > $this->parameters->stableBand => 'up',
                $change < -$this->parameters->stableBand => 'down',
                default => 'stable',
            },
            'trend' => MarketTrend::fromDailyValues([...$recent, $forecast->predicted_value])?->value,
            'reasons' => $forecast->reasons,
        ];
    }
}
```

`PlayersController::show()`: add `ValueForecastPresenter $valueForecasts` to the parameters (import
`App\Services\ValueForecast\ValueForecastPresenter`) and, next to `maxBid`:

```php
            'valueForecast' => HandleGodMode::isEnabled($request)
                ? $valueForecasts->forPlayer($player, $season)
                : null,
```

- [ ] **Step 4: Run the tests**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/PlayersControllerTest.php`
Expected: PASS (all).

- [ ] **Step 5: Pint, PHPStan, commit**

```bash
herd php vendor/bin/pint --dirty --format agent
herd php vendor/bin/phpstan analyse --memory-limit=2G
git add app/Services/ValueForecast/ValueForecastPresenter.php app/Http/Controllers/PlayersController.php tests/Feature/Http/Controllers/PlayersControllerTest.php
git commit -m "feat: send tomorrow's value forecast to the ficha in god mode"
```

---

### Task 15: `HqGodMarketSection` — readouts and chart

**Files:**
- Rename: `resources/js/components/hq-max-bid-card.tsx` → `resources/js/components/hq-god-market-section.tsx` (`git mv`)
- Modify: `resources/js/types/models.ts`, `resources/js/pages/players/show.tsx`

**Interfaces:**
- Consumes: `valueForecast` prop (Task 14), `maxBid.day_one_forecast` (Task 9).
- Produces: `export function HqGodMarketSection({ estimate, forecast, playerStatus }: { estimate: MaxBidEstimate; forecast: ValueForecast | null; playerStatus: PlayerStatus })`;
  TS types `ValueForecast`, `ValueForecastReason`, `ValueForecastReasonKind`, `ValueForecastDirection`.

Frontend-only task: there is no JS test runner; the checks are `npm run types:check`, `npm run lint:check`,
`npm run build` and the Task 14 feature tests. Visual check at the end of Task 16.

- [ ] **Step 1: Types** — in `resources/js/types/models.ts`, add to `MaxBidEstimate` (after `reference_date`):

```ts
    /** The value forecast used as day 1 (the projection is re-anchored to it); null without one. */
    day_one_forecast: number | null;
    /** Forecast − the formula's own day 1, in euros; null without a forecast. */
    day_one_offset: number | null;
```

and after it:

```ts
export type ValueForecastDirection = 'up' | 'stable' | 'down';

export type ValueForecastReasonKind =
    | 'inertia'
    | 'streak'
    | 'market'
    | 'match_yesterday'
    | 'match_today'
    | 'match_before'
    | 'calendar'
    | 'baseline'
    | 'floor';

export interface ValueForecastReason {
    kind: ValueForecastReasonKind;
    label: string;
    /** Percentage points of tomorrow's change, e.g. 5.65. */
    impact_pct: number;
}

/** Tomorrow's value, god mode only (App\Services\ValueForecast\ValueForecastPresenter). */
export interface ValueForecast {
    reference_date: string;
    target_date: string;
    value: number;
    predicted_value: number;
    /** predicted_value − value, in euros. */
    change: number;
    /** Percent, e.g. 5.2. */
    change_pct: number;
    /** 80 % interval. */
    low: number;
    high: number;
    /** 0–1. */
    up_probability: number;
    direction: ValueForecastDirection;
    /** The market trend the forecast value would draw. */
    trend: MarketTrend | null;
    reasons: ValueForecastReason[];
}
```

- [ ] **Step 2: Rename the component**

```bash
git mv resources/js/components/hq-max-bid-card.tsx resources/js/components/hq-god-market-section.tsx
```

In the renamed file:
1. Imports: add `import type { HqLedTone } from '@/components/hq-led';`,
   `import { HqMarketValueDifference } from '@/components/hq-market-trend-icon';` and extend the types import with
   `ValueForecast, ValueForecastDirection`.
2. Replace `BidReadout` with a general `LedAmount`:

```tsx
/** An amount as a dot-matrix readout, its thousands dots set in mono so they stay legible. */
function LedAmount({ amount, tone }: { amount: number; tone: HqLedTone }) {
    const groups = Math.round(amount).toLocaleString('es-ES').split('.');

    return (
        <HqLed
            tone={tone}
            glow
            className="mt-2.5 block text-[34px] whitespace-nowrap sm:text-[40px]"
        >
            {groups.map((group, index) => (
                <span key={index}>
                    {index > 0 && (
                        <i className="mx-px font-mono text-[0.55em] font-bold not-italic">
                            .
                        </i>
                    )}
                    {group}
                </span>
            ))}
            <span className="text-[24px]"> €</span>
        </HqLed>
    );
}
```

   and in `Headline` use `<LedAmount amount={estimate.bid} tone="amber" />` (the mock's amber bid).
3. Add the forecast readout:

```tsx
const FORECAST_TONES: Record<ValueForecastDirection, HqLedTone> = {
    up: 'lime',
    stable: 'paper',
    down: 'live',
};

function formatSignedPercent(value: number): string {
    return `${value.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'always',
    })} %`;
}

function ForecastReadout({ forecast }: { forecast: ValueForecast }) {
    const likelyUp = forecast.up_probability >= 0.5;

    return (
        <div>
            <div className="flex items-center justify-between gap-2">
                <p className="hq-label">
                    Mañana · {formatReferenceDate(forecast.target_date)}
                </p>
                <span
                    className={cn(
                        'border px-1.5 py-0.5 font-mono text-[11px] leading-none font-bold whitespace-nowrap tabular-nums',
                        likelyUp
                            ? 'border-hq-lime/50 text-hq-lime'
                            : 'border-hq-neg/50 text-hq-neg',
                    )}
                >
                    P(sube) {formatPercent(forecast.up_probability)}
                </span>
            </div>
            <LedAmount
                amount={forecast.predicted_value}
                tone={FORECAST_TONES[forecast.direction]}
            />
            <p className="mt-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 font-mono text-xs text-hq-moss">
                <HqMarketValueDifference
                    difference={forecast.change}
                    trend={forecast.trend}
                />
                <b
                    className={cn(
                        'font-bold tabular-nums',
                        toneClass(forecast.change_pct),
                    )}
                >
                    {formatSignedPercent(forecast.change_pct)}
                </b>
                <span className="whitespace-nowrap">
                    · rango {formatMillions(forecast.low)} –{' '}
                    {formatMillions(forecast.high)}
                </span>
            </p>
        </div>
    );
}
```

4. `ProjectionChart` takes `{ estimate, forecast }: { estimate: MaxBidEstimate; forecast: ValueForecast | null }`.
   Right after `const projection = …`:

```tsx
    // The forecast's 80 % range, drawn on day 1 only when the projection was
    // re-anchored to that same forecast.
    const range =
        forecast !== null && estimate.day_one_forecast !== null
            ? { low: forecast.low, high: forecast.high }
            : null;
```

   Include it in the y-domain: `const domainValues = [...projection, ...(estimate.bid !== null ? [estimate.bid] : []), ...(range !== null ? [range.low, range.high] : [])];`
   Draw it just before the projection `<polyline>`:

```tsx
                {range !== null && (
                    <g stroke="var(--color-hq-lime)" opacity={0.85}>
                        <line
                            x1={x(1)}
                            x2={x(1)}
                            y1={y(range.high)}
                            y2={y(range.low)}
                            strokeWidth={2}
                        />
                        <line
                            x1={x(1) - 4}
                            x2={x(1) + 4}
                            y1={y(range.high)}
                            y2={y(range.high)}
                        />
                        <line
                            x1={x(1) - 4}
                            x2={x(1) + 4}
                            y1={y(range.low)}
                            y2={y(range.low)}
                        />
                    </g>
                )}
```

   And in the tooltip, after the "Oferta:" line:

```tsx
                        {tooltip.day === 1 && range !== null && (
                            <div className="mt-1 text-hq-lime">
                                Previsión · rango {formatMillions(range.low)} –{' '}
                                {formatMillions(range.high)}
                            </div>
                        )}
```

5. Rename the component and its props, add the header and the readout (keep the confidence state/handlers as they are):

```tsx
interface HqGodMarketSectionProps {
    estimate: MaxBidEstimate;
    forecast: ValueForecast | null;
    playerStatus: PlayerStatus;
}

/**
 * God mode «Mercado» section of the ficha (mock _prevision-valor.html, variant
 * A), fenced by the `hq-god-frame` tape: tomorrow's value forecast and the
 * max profitable bid side by side, the bid's 14-day projection (day 1 = the
 * forecast, with its 80 % range) and the factors behind both.
 */
export function HqGodMarketSection({
    estimate,
    forecast,
    playerStatus,
}: HqGodMarketSectionProps) {
```

   The `<section>` gets `aria-label="Mercado"`; its first child becomes:

```tsx
            <h2 className="px-3.5 pt-3 font-mono text-[11px] leading-none font-bold tracking-[0.07em] text-hq-amber uppercase sm:px-5">
                Mercado
            </h2>
```

   and the left column of the top grid:

```tsx
                <div className="space-y-5">
                    {forecast !== null && (
                        <ForecastReadout forecast={forecast} />
                    )}
                    <div>
                        <Headline
                            estimate={estimate}
                            playerStatus={playerStatus}
                        />
                        {hasProjection && (
                            <>
                                <ConfidenceStepper
                                    percent={confidencePercent}
                                    onChange={handleConfidenceChange}
                                />
                                <ConfidenceLabel
                                    lockDays={estimate.lock_days}
                                />
                            </>
                        )}
                    </div>
                </div>

                {hasProjection && (
                    <ProjectionChart estimate={estimate} forecast={forecast} />
                )}
```

- [ ] **Step 3: Wire the page** — in `resources/js/pages/players/show.tsx`: import
  `HqGodMarketSection` from `@/components/hq-god-market-section` (drop the `HqMaxBidCard` import), add
  `valueForecast: ValueForecast | null;` to the page props next to `maxBid`, destructure it, and render:

```tsx
            {maxBid !== null && (
                <HqGodMarketSection
                    estimate={maxBid}
                    forecast={valueForecast}
                    playerStatus={player.status}
                />
            )}
```

- [ ] **Step 4: Check**

Run: `npm run types:check`, `npm run lint:check`, `npm run build`, and
`herd php artisan test --compact tests/Feature/Http/Controllers/PlayersControllerTest.php`
Expected: all clean / PASS. `grep -rn "hq-max-bid-card\|HqMaxBidCard" resources/js` returns nothing.

- [ ] **Step 5: Commit**

```bash
git add resources/js/types/models.ts resources/js/components/hq-god-market-section.tsx resources/js/pages/players/show.tsx
git commit -m "feat: turn the max bid card into the god market section with tomorrow's value"
```

---

### Task 16: `HqGodMarketSection` — factor columns (MAÑ / PUJA / INFO), rivals, no footer

**Files:**
- Modify: `resources/js/components/hq-god-market-section.tsx`, `resources/js/lib/rival-difficulty.ts`

**Interfaces:**
- Consumes: `ValueForecast.reasons` (kinds from Task 6), `MaxBidEstimate` factors, `HqDifficultyBars`
  (`difficulty: number` 0–10, `layout: 'stack' | 'inline' | 'gauge'`), `STATUS_LABELS`.
- Produces: `difficultyFromEase(ease: number): number` in `rival-difficulty.ts`.

- [ ] **Step 1: The ease → 0–10 helper** — in `resources/js/lib/rival-difficulty.ts`:

```ts
/**
 * MaxBidCalculator's rival ease (−1 hard … +1 easy) back on the 0–10
 * difficulty scale — the exact inverse of MatchDifficultyResult's
 * `rivalEase = (5 − difficulty) / 5`, one decimal.
 */
export function difficultyFromEase(ease: number): number {
    return Math.round((5 - 5 * ease) * 10) / 10;
}
```

- [ ] **Step 2: Chips and rows** — in the section file, add:

```tsx
/** Which calculation uses a factor: the forecast (MAÑ), the bid (PUJA) or none, shown as context (INFO). */
type FactorUse = 'forecast' | 'bid' | 'info';

const FACTOR_USE_LABELS: Record<FactorUse, string> = {
    forecast: 'MAÑ',
    bid: 'PUJA',
    info: 'INFO',
};

const FACTOR_USE_CLASSES: Record<FactorUse, string> = {
    forecast: 'border-hq-lime/50 text-hq-lime',
    bid: 'border-hq-gold/50 text-hq-gold',
    info: 'border-hq-border-bright text-hq-moss',
};

function FactorUses({ uses }: { uses: FactorUse[] }) {
    return (
        <span className="ml-1.5 inline-flex gap-1 align-middle">
            {uses.map((use) => (
                <span
                    key={use}
                    className={cn(
                        'border px-1 font-mono text-[11px] leading-[15px] font-bold',
                        FACTOR_USE_CLASSES[use],
                    )}
                >
                    {FACTOR_USE_LABELS[use]}
                </span>
            ))}
        </span>
    );
}

/** Forecast reasons shown in the Mercado column; the rest go to Deportivo. */
const MARKET_REASON_KINDS: ValueForecastReasonKind[] = [
    'inertia',
    'streak',
    'market',
    'baseline',
    'floor',
];

function formatImpact(impactPct: number): string {
    return `${impactPct.toLocaleString('es-ES', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
        signDisplay: 'always',
    })} pp`;
}

function ReasonRows({ reasons }: { reasons: ValueForecastReason[] }) {
    return reasons.map((reason) => (
        <BreakdownRow
            key={reason.kind}
            label={
                <>
                    {reason.label}
                    <FactorUses uses={['forecast']} />
                </>
            }
            value={formatImpact(reason.impact_pct)}
            valueClass={toneClass(reason.impact_pct)}
        />
    ));
}
```

  (Extend the types import with `ValueForecastReason, ValueForecastReasonKind`; import `HqDifficultyBars` from
  `@/components/hq-difficulty-bars` and `difficultyFromEase` from `@/lib/rival-difficulty`.)

- [ ] **Step 3: Replace `RivalRow`** with 0–10 bars:

```tsx
function RivalRow({ rival }: { rival: MaxBidRival }) {
    return (
        <div className="flex items-center justify-between gap-2.5 border-t border-hq-border py-[7px] font-mono text-[11.5px] leading-tight text-hq-moss">
            <span className="flex min-w-0 items-center gap-1.5">
                <img
                    src={rival.team.logo}
                    alt=""
                    className="size-4 shrink-0 object-contain"
                />
                <span className="truncate">
                    {rival.team.short_name} ({rival.position}º) · en{' '}
                    {rival.days_until} días
                </span>
            </span>
            <HqDifficultyBars
                difficulty={difficultyFromEase(rival.difficulty)}
                layout="inline"
                className="shrink-0"
            />
        </div>
    );
}
```

- [ ] **Step 4: Replace the columns block and drop the footer** — replace everything from
  `{hasProjection && (<div className="grid grid-cols-1 border-t …">` to the end of the section (including the footer
  `<p>` "Mejor oferta esperada…") with:

```tsx
            {(hasProjection || forecast !== null) && (
                <div
                    className={cn(
                        'grid grid-cols-1 border-t border-hq-amber/25',
                        hasProjection
                            ? 'min-[68.75rem]:grid-cols-3'
                            : 'min-[68.75rem]:grid-cols-2',
                    )}
                >
                    <div className="border-b border-hq-border px-3.5 py-3 sm:px-4 min-[68.75rem]:border-r min-[68.75rem]:border-b-0">
                        <ColumnHeading>Mercado</ColumnHeading>
                        <ReasonRows
                            reasons={(forecast?.reasons ?? []).filter((reason) =>
                                MARKET_REASON_KINDS.includes(reason.kind),
                            )}
                        />
                        {hasProjection && (
                            <>
                                <BreakdownRow
                                    label={
                                        <>
                                            Momentum (3 días)
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDaily(estimate.momentum_increment ?? 0)}
                                    valueClass={toneClass(estimate.momentum_increment ?? 0)}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Mercado general
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDaily(estimate.market_adjustment ?? 0)}
                                    valueClass={toneClass(estimate.market_adjustment ?? 0)}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Deportivo
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDaily(estimate.sport_adjustment ?? 0)}
                                    valueClass={toneClass(estimate.sport_adjustment ?? 0)}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Proyección día {estimate.lock_days}
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatMillions(estimate.projected_day14 ?? 0)}
                                    valueClass="text-hq-paper"
                                />
                            </>
                        )}
                    </div>

                    <div
                        className={cn(
                            'px-3.5 py-3 sm:px-4',
                            hasProjection &&
                                'border-b border-hq-border min-[68.75rem]:border-r min-[68.75rem]:border-b-0',
                        )}
                    >
                        <ColumnHeading>
                            Deportivo
                            {hasProjection &&
                                ` · S ${formatSigned(estimate.sport_score ?? 0)}`}
                        </ColumnHeading>
                        <ReasonRows
                            reasons={(forecast?.reasons ?? []).filter(
                                (reason) => !MARKET_REASON_KINDS.includes(reason.kind),
                            )}
                        />
                        <BreakdownRow
                            label={
                                <>
                                    Estado
                                    <FactorUses uses={['bid', 'info']} />
                                </>
                            }
                            value={STATUS_LABELS[playerStatus]}
                            valueClass={playerStatus === 'ok' ? 'text-hq-lime' : 'text-hq-neg'}
                        />
                        {hasProjection && (
                            <>
                                <BreakdownRow
                                    label={
                                        <>
                                            Forma
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatSigned(estimate.form ?? 0)}
                                    valueClass={toneClass(estimate.form ?? 0)}
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            {`Participación reciente ${estimate.recent_participation
                                                .map((match) => `${match.minutes}'`)
                                                .join(' · ')}`}
                                            <FactorUses uses={['bid']} />
                                        </>
                                    }
                                    value={formatDecimal(estimate.recent_participation_share ?? 0)}
                                    valueClass="text-hq-paper"
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Titularidad prevista
                                            <FactorUses uses={['bid', 'info']} />
                                        </>
                                    }
                                    value={
                                        estimate.next_start_probability === null
                                            ? '—'
                                            : formatPercent(estimate.next_start_probability)
                                    }
                                    valueClass="text-hq-paper"
                                />
                                <BreakdownRow
                                    label={
                                        <>
                                            Participación usada
                                            <FactorUses uses={['bid']} />
                                            <span className="block text-[11px] text-hq-moss/70">
                                                {participationMixHint(estimate)}
                                            </span>
                                        </>
                                    }
                                    value={formatDecimal(estimate.participation ?? 0)}
                                    valueClass="text-hq-paper"
                                />
                                {firstRival !== null && (
                                    <BreakdownRow
                                        label={
                                            <>
                                                Próximo partido en {firstRival.days_until} días
                                                <FactorUses uses={['bid']} />
                                            </>
                                        }
                                        value={`peso ${formatDecimal(firstRival.weight)}`}
                                        valueClass="text-hq-paper"
                                    />
                                )}
                            </>
                        )}
                    </div>

                    {hasProjection && (
                        <div className="px-3.5 py-3 sm:px-4">
                            <ColumnHeading>
                                Próximos rivales
                                <FactorUses uses={['bid', 'info']} />
                            </ColumnHeading>
                            {estimate.upcoming_rivals.map((rival) => (
                                <RivalRow
                                    key={`${rival.team.id}-${rival.days_until}`}
                                    rival={rival}
                                />
                            ))}
                        </div>
                    )}
                </div>
            )}
        </section>
```

  (The "Participación usada" hint goes from 10.5 px to 11 px: functional text is never below 11 px. Prettier will
  reflow long lines: run `npm run format` on the file.)

- [ ] **Step 5: Check**

Run: `npx prettier --write resources/js/components/hq-god-market-section.tsx resources/js/lib/rival-difficulty.ts`,
`npm run types:check`, `npm run lint:check`, `npm run build`.
Expected: clean. `grep -n "Mejor oferta esperada" resources/js/components/hq-god-market-section.tsx` returns nothing.

- [ ] **Step 6: Visual check (user, in the browser)**

Don't start `npm run dev`. After `npm run build`, ask the user to open a player ficha on the Herd site with god mode on
(they have the key) at desktop width and at 390 px, with: a rising player (lime LED, P(sube) chip, bigote on day 1),
a falling one (red LED), an injured one (forecast + "Sin rentabilidad", two columns, no chart) and one without a
forecast (the bid alone, no MAÑ rows). Check: no horizontal scroll at 390 px, chips readable, no footer text.

- [ ] **Step 7: Commit**

```bash
git add resources/js/components/hq-god-market-section.tsx resources/js/lib/rival-difficulty.ts
git commit -m "feat: show which factors drive tomorrow's value and the bid in the market section"
```

---

### Task 17 (CONFIRMED by the user on 2026-09-30): «Mañana» as verdict evidence in the comparator

> Ask the user first: "¿Añado la previsión de mañana como prueba del veredicto god del comparador (+0,5 en Fichar,
> +0,8 en Vender, normalizada entre los comparados)?" Skip the task if the answer isn't a clear yes.

**Files:**
- Modify: `app/Services/ComparedPlayers.php`, `app/Http/Controllers/PlayerComparisonController.php`
- Modify: `resources/js/types/models.ts`, `resources/js/components/compare/derive.ts`
- Test: `tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php`

**Interfaces:**
- Consumes: `ValueForecastPresenter::forPlayer` (Task 14).
- Produces: `ComparedPlayers::forIds(array $ids, Season $season, int $fromWeek, bool $withForecast = false)`; each
  compared player gains `forecast: {predicted_value: int, change_pct: float, up_probability: float} | null`
  (always `null` without god mode).

- [ ] **Step 1: Write the failing tests** — append to `PlayerComparisonControllerTest.php` (follow the file's existing
  setup for a comparable player; add `use App\Models\ValueForecast;`):

```php
test('god mode adds each compared player\'s forecast for tomorrow', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString(), 'value' => 10_000_000]);
    ValueForecast::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'reference_date' => now()->toDateString(),
        'target_date' => now()->addDay()->toDateString(),
        'predicted_value' => 10_300_000,
        'change_pct' => 3.0,
        'up_probability' => 0.95,
    ]);

    $this->withCookie('god_mode', '1')
        ->get(route('players.compare', ['ids' => (string) $player->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('players.0.forecast.predicted_value', 10_300_000)
            ->where('players.0.forecast.change_pct', 3.0)
            ->where('players.0.forecast.up_probability', 0.95));

    $this->get(route('players.compare', ['ids' => (string) $player->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('players.0.forecast', null));
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php --filter=forecast`
Expected: FAIL — `players.0.forecast` missing.

- [ ] **Step 3: Backend**

`ComparedPlayers`: inject `private readonly ValueForecastPresenter $valueForecasts` in the constructor; add
`bool $withForecast = false` to `forIds()` (document it: "god mode only"); in the row array add

```php
                    'forecast' => $withForecast ? $this->forecastFor($player, $season) : null,
```

and the helper

```php
    /**
     * @return array{predicted_value: int, change_pct: float, up_probability: float}|null
     */
    private function forecastFor(Player $player, Season $season): ?array
    {
        $forecast = $this->valueForecasts->forPlayer($player, $season);

        return $forecast === null ? null : [
            'predicted_value' => $forecast['predicted_value'],
            'change_pct' => $forecast['change_pct'],
            'up_probability' => $forecast['up_probability'],
        ];
    }
```

(also add `forecast` to the `ComparedPlayerShape` PHPStan type). `PlayerComparisonController::show()`:

```php
            'players' => fn (): array => $comparedPlayers->forIds($ids, $season, $week, withForecast: HandleGodMode::isEnabled($request)),
```

(import `App\Http\Middleware\HandleGodMode`).

- [ ] **Step 4: Frontend** — `ComparedPlayer` in `models.ts`:

```ts
    /** Tomorrow's value forecast — god mode only, null otherwise. */
    forecast: {
        predicted_value: number;
        change_pct: number;
        up_probability: number;
    } | null;
```

In `derive.ts` add a formatter next to the others:

```ts
function formatForecastPct(value: number): string {
    return `${value.toLocaleString('es-ES', { maximumFractionDigits: 1, signDisplay: 'always' })} %`;
}
```

`buyLens`: `const forecastValues = players.map((player) => player.forecast?.change_pct ?? null); const risesTomorrow = normalizeAmong(forecastValues);`
add `+ 0.5 * risesTomorrow[index]` to the score, and append this row to `rows` only when
`forecastValues.some((value) => value !== null)`:

```ts
            {
                label: 'Mañana',
                hint: 'previsión',
                texts: forecastValues.map((value) =>
                    value === null ? '—' : formatForecastPct(value),
                ),
                values: forecastValues,
                lowerIsBetter: false,
                mark: 'best',
            },
```

`sellLens`: `const fallsTomorrow = normalizeAmong(forecastValues, true);` add `+ 0.8 * fallsTomorrow[index]` to the
score and the same row with `mark: 'worst'`. Update the `verdict()` docblock: "God mode only … «Mañana» (the value
forecast) weighs +0,5 in Fichar and +0,8 in Vender."

- [ ] **Step 5: Check and commit**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php tests/Feature/Services/ComparedPlayersTest.php tests/Feature/Http/Controllers/Api`,
`npm run types:check`, `npm run lint:check`, `npm run build`.

```bash
herd php vendor/bin/pint --dirty --format agent
git add app/Services/ComparedPlayers.php app/Http/Controllers/PlayerComparisonController.php resources/js/types/models.ts resources/js/components/compare/derive.ts tests/Feature/Http/Controllers/PlayerComparisonControllerTest.php
git commit -m "feat: weigh tomorrow's value forecast in the comparator's god verdict"
```

---

## Done

After the last task: `herd php vendor/bin/phpstan analyse --memory-limit=2G`, `npm run build`, and ask the user to run
the full suite (`herd php artisan test --compact`). Report the reproduction table (Task 7), the day-1 comparison
(Task 10) and the calibration table and decision (Task 12). Do not merge into `main` without the user's explicit OK.

Later (out of scope, spec §Fuera): the forecast in the players list, the home market panel, the comparator's «Mañana»
row in view A and a `/jugadores/prevision` page; status rules and start probability / rival difficulty as model
variables once ~4 weeks of `player_daily_signals` exist (repeat `season:backtest-value-forecast` around J12–J15).
