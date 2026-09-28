# Titularidades (start probabilities) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** For every LaLiga player, show how likely he is to START his team's next match. The data is scraped from FútbolFantasy's team pages and shown on the match, team and manager pages, and it gives way to the confirmed lineup once one exists.

**Architecture:** A data pipeline comes first. It has a pure HTML parser (`FutbolFantasyTeamPageParser`, PHP's built-in `Dom\HTMLDocument`), a four-rule player linker (`FutbolFantasyPlayerLinker`), a Saloon connector and the `season:sync-start-probabilities` command. The command runs every 10 min with per-team due logic and upserts `fixture_lineup_probabilities`, one row per player and fixture. The worldcup26 live sync opens its pre-match window 1 h 30 min before kickoff. A read-side service (`StartProbabilities`) turns rows and confirmed lineups (worldcup26 first, FútbolFantasy second) into Inertia props for three pages. The UI tasks then add a probable/confirmed XI section to the match page, a probable-XI half pitch to the team page's jornada aside, and a bar and % under each manager roster player's next match.

**Tech Stack:** Laravel 13 (PHP 8.5, `Dom\HTMLDocument`), Saloon v4, Pest 5, Larastan level 7, Inertia v3 + React 19 + TypeScript, Tailwind v4 (Comando HQ tokens), Wayfinder, lucide-react.

**Spec:** `docs/superpowers/specs/2026-09-27-start-probabilities-design.md` (binding). Display mocks are in `public/_titulares.html` (untracked): match page variant A, team page variant B, and manager page variant A placed under the next-match cell.

## Global Constraints

- Run PHP, Artisan and Composer through Herd: `herd php artisan …`, `herd composer …`. Tests: `herd php artisan test --compact <path>`. Format: `herd php vendor/bin/pint --dirty --format agent`. Static analysis: `herd composer phpstan` (Larastan level 7) before every PHP commit.
- Frontend checks: `npm run types:check`, `npm run lint:check`, `npx prettier --check <touched files>`, `npm run build`. **Never start `npm run dev`.**
- Every PHP file starts with `declare(strict_types=1);`. Test files follow their siblings; the files named here say which.
- Constructor property promotion, explicit return types, curly braces everywhere, PHPDoc array shapes, TitleCase enum keys.
- String columns that don't store an enum are non-nullable and default to `''`. This plan adds no string column.
- **Add no dependencies.** HTML is parsed with PHP 8.4+'s built-in `Dom\HTMLDocument` (ext-dom ships with PHP, so it is in the FrankenPHP image too). Do not use symfony/dom-crawler.
- Create no new base folders. Services and DTOs go in `app/Services/`, enums in `app/Enums/`, the connector in `app/Http/Integrations/FutbolFantasy/`, and test fixtures in `tests/Fixtures/futbolfantasy/`.
- Source: `https://www.futbolfantasy.com/laliga/equipos/{slug}`. Send an **anonymous project User-Agent (never personal data)** and gzip, wait **10–30 s between requests**, and make **one attempt per page** (no retries).
- Schedule the sync every 10 min. For each team: next fixture within **48 h** → fetch on every run; further away → **at most every 6 h**; after kickoff → **never**. `--force` ignores the due logic.
- Linking order, first hit wins: stored `players.futbolfantasy_id` → same team + **exact** `data-valor-laliga-fantasy` against `player_markets` of the **last 3 days** (tie-break total points, then position) → same team + normalised name → manual map. **Unlinked FF players are logged, never guessed.**
- Probabilities are stored on **that team's fixture with `week_number = n`** from the page's "J{n}". A `data-rival` mismatch, or a missing heading or jornada, means nothing is stored and a warning is logged. So does a page that fails or parses 0 players: that team's rows stay untouched.
- **Rows in `fixture_lineup_probabilities` are kept after kickoff and are never deleted.** They back the "Sorpresa / Se cae · era N %" marks and serve as history. The pages simply stop showing the % once a lineup is confirmed or the match has kicked off.
- Confirmed-lineup sources, in order: **worldcup26** (`fixture_lineups`, primary), then FF "Alineación confirmada" (`confirmed_starter`, fallback). When they disagree, worldcup26 wins. The worldcup26 live sync starts **1 h 30 min** before kickoff.
- Injury and suspension come from our `players.status` (LaLiga Fantasy), never from FF flags.
- Display: doubts **< 60 %** are dimmed with a dashed frame. Bench/doubts list the players at **≥ 30 %**, then a "**+N < 30 %**" line. **≥ 90 % pitch badges are lilac (`--color-hq-violet`)**; other values use the tone scale ≥ 70 lime, 40–69 gold, < 40 moss, injured/suspended red. Data older than **48 h** is stale: show "Datos de hace N días" and mute the bars.
- Every surface credits the data "Probabilidades: FútbolFantasy" and links to the team page.
- **Max bid is out of scope:** `MaxBidCalculator` does not change.
- `PlayerFactory` picks a random `status`. Any test whose outcome depends on status passes `'status' => PlayerStatus::Ok` (or the status under test).
- `player_markets.date` is cast `immutable_date`. Compare it with `whereDate()`.
- Work happens on branch `feature/start-probabilities` with no worktree. Make one commit per task, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Never merge to `main` without the user's explicit OK.
- **Task 4 ends with a user checkpoint.** Do not start Task 5 until the user has seen the real `--force` run summary.

## Review Focus

- **FF confirms the lineup after we stored predictions:** the confirmed page carries `Titular`/`Suplente` and no %. The last predicted % and FF's probable XI must survive, or "Sorpresa / Se cae · era N %" has nothing to show. Pinned in Task 4.
- **Two FF players whose names both match one of ours** (two "Rodríguez", or a slug that contains a teammate's surname): at most one link, and the other FF player stays unlinked. Pinned in Task 3.
- **A player linked earlier who has since moved to another LaLiga club:** rule 1 (stored id) must still link him on his new team's page, and no heuristic may hand his FF id to someone else. That would violate the unique index. Pinned in Task 3.
- **The FF page still shows a jornada whose match has already kicked off** (FF is slow to switch, or the match was brought forward): nothing is written and the existing rows keep the history. Pinned in Task 4.
- **A match with data for one side only** (the other team's page failed or isn't linked yet): the match page shows that side plus a "sin datos" column and must not crash. Pinned in Task 6 (service returns `guest: null`) and rendered in Task 7.

---

## File Structure

| File | Responsibility |
|---|---|
| `database/migrations/2026_09_27_100000_add_futbolfantasy_id_to_players_table.php` (create) | `players.futbolfantasy_id` (nullable unsigned int, unique). |
| `database/migrations/2026_09_27_100100_create_fixture_lineup_probabilities_table.php` (create) | The rows: player × fixture, `probability`, `predicted_starter`, `confirmed_starter`, `fetched_at`. |
| `app/Models/FixtureLineupProbability.php` + `database/factories/FixtureLineupProbabilityFactory.php` (create) | Model + factory. |
| `app/Models/Player.php` (modify) | `futbolfantasy_id`, `lineupProbabilities()`, `next_start` docblock. |
| `app/Services/FutbolFantasyTeams.php` (create) | 20-entry map `teams.fantasy_id → FF slug`, `FF slug → FF team code`, page URLs. |
| `app/Services/FutbolFantasyPlayer.php`, `FutbolFantasyTeamPage.php`, `FutbolFantasyPageException.php` (create) | Parser DTOs + failure. |
| `app/Services/FutbolFantasyTeamPageParser.php` (create) | Pure HTML → `FutbolFantasyTeamPage`. |
| `tests/Fixtures/futbolfantasy/real-madrid-posible.html`, `real-madrid-confirmada.html` (create) | Trimmed real markup. |
| `app/Enums/FutbolFantasyLinkRule.php` (create) | `stored_id` / `market_value` / `name` / `manual_map`. |
| `app/Services/FutbolFantasyPlayerLinker.php` (create) | The four linking rules + `PLAYER_MAP`. |
| `app/Http/Integrations/FutbolFantasy/FutbolFantasyConnector.php`, `Requests/GetTeamPageRequest.php` (create) | Saloon connector (UA, gzip, 20 s timeout) + request. |
| `config/services.php` (modify) | `futbolfantasy.base_url`. |
| `app/Console/Commands/SyncCurrentSeasonStartProbabilities.php` (create) | `season:sync-start-probabilities [--force]`: due logic, fetch, parse, link, upsert, summary. |
| `bootstrap/app.php` (modify) | Schedule every 10 min. |
| `app/Console/Commands/SyncLiveSeasonMatchData.php` (modify) | Pre-match window 1 h → 90 min. |
| `app/Services/StartProbabilities.php` (create) | Read side: team blocks per fixture, team's next fixture, players' next fixture; confirmed precedence; stale flag; attribution URL. |
| `app/Http/Controllers/FixturesController.php`, `TeamsController.php`, `SeasonManagersController.php` (modify) | New props `startProbabilities` / `player.next_start`. |
| `resources/js/types/models.ts` (modify) | `StartProbabilityEntry`, `StartProbabilityTeamBlock`, `FixtureStartProbabilities`, `TeamNextStartProbabilities`, `PlayerNextStart`, `Player.next_start`. |
| `resources/js/lib/start-probability.ts` (create) | Tones, thresholds, outcome, split into XI/bench/rest/bajas, pitch slot layout, data age. |
| `resources/js/components/hq-start-probability.tsx` (create) | Shared UI: 10-cell `HqStartMeter`, outcome chip, state label, stale banner, attribution, legend, pitch token. |
| `resources/js/components/hq-match-pitch.tsx` (modify) | Export its pitch markings as `MatchPitchLines`. |
| `resources/js/components/hq-probable-match-pitch.tsx` (create) | Landscape pitch with both probable/confirmed XIs. |
| `resources/js/components/hq-start-probabilities-section.tsx` (create) | Match page section: header, stale, pitch + list columns, bench/doubts, +N, bajas, legend, attribution. |
| `resources/js/pages/fixtures/show.tsx` (modify) | Render the section instead of the empty state for an upcoming match. |
| `resources/js/components/hq-probable-half-pitch.tsx` (create) | Team page aside: probable-XI half pitch, doubts, bajas, attribution. |
| `resources/js/pages/teams/show.tsx` (modify) | Half pitch instead of "Sin alineación" for the upcoming jornada. |
| `resources/js/pages/season-managers/roster-list.tsx`, `show.tsx` (modify) | Bar + % under each next-match cell; header "J{n} · X/Y XI prob."; attribution. |

---

### Task 1: Storage and the FútbolFantasy team map

**Files:**
- Create: `database/migrations/2026_09_27_100000_add_futbolfantasy_id_to_players_table.php`
- Create: `database/migrations/2026_09_27_100100_create_fixture_lineup_probabilities_table.php`
- Create: `app/Models/FixtureLineupProbability.php`
- Create: `database/factories/FixtureLineupProbabilityFactory.php`
- Create: `app/Services/FutbolFantasyTeams.php`
- Modify: `app/Models/Player.php` (docblock, `#[Fillable]`, casts, new relation)
- Test: `tests/Feature/Models/FixtureLineupProbabilityTest.php`, `tests/Unit/Services/FutbolFantasyTeamsTest.php`

**Interfaces:**
- Produces:
  - Table `fixture_lineup_probabilities(id, player_id, fixture_id, probability tinyint unsigned null, predicted_starter bool default false, confirmed_starter bool null, fetched_at timestamp, timestamps)`, unique (`player_id`, `fixture_id`).
  - `App\Models\FixtureLineupProbability` with `player(): BelongsTo<Player>`, `fixture(): BelongsTo<Fixture>`, properties `int|null $probability`, `bool $predicted_starter`, `bool|null $confirmed_starter`, `CarbonImmutable $fetched_at`.
  - `Player::$futbolfantasy_id` (`int|null`, fillable), `Player::lineupProbabilities(): HasMany<FixtureLineupProbability>`, and the docblock-only dynamic attribute `Player::$next_start` (filled in Task 6).
  - `App\Services\FutbolFantasyTeams`: `SLUGS: array<int, string>` (teams.fantasy_id → slug), `CODES: array<string, string>` (slug → FF team code), `TEAM_PAGE_URL`, `slugFor(int $fantasyId): ?string`, `codeFor(int $fantasyId): ?string`, `pageUrlFor(int $fantasyId): ?string`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Models/FixtureLineupProbabilityTest.php`:

```php
<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\Player;
use App\Models\FixtureLineupProbability;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
});

test('belongs to a player and a fixture and casts its columns', function (): void {
    $row = FixtureLineupProbability::factory()->create([
        'probability' => 70,
        'predicted_starter' => true,
        'confirmed_starter' => null,
    ])->refresh();

    expect($row->player)->toBeInstanceOf(Player::class)
        ->and($row->fixture)->toBeInstanceOf(Fixture::class)
        ->and($row->probability)->toBe(70)
        ->and($row->predicted_starter)->toBeTrue()
        ->and($row->confirmed_starter)->toBeNull()
        ->and($row->fetched_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('a probability can be missing and a lineup confirmed without one', function (): void {
    $row = FixtureLineupProbability::factory()->create([
        'probability' => null,
        'confirmed_starter' => false,
    ])->refresh();

    expect($row->probability)->toBeNull()
        ->and($row->predicted_starter)->toBeFalse()
        ->and($row->confirmed_starter)->toBeFalse();
});

test('keeps a single row per player and fixture', function (): void {
    $row = FixtureLineupProbability::factory()->create();

    FixtureLineupProbability::factory()->create([
        'player_id' => $row->player_id,
        'fixture_id' => $row->fixture_id,
    ]);
})->throws(QueryException::class);

test('a player stores its FútbolFantasy id and lists its start probabilities', function (): void {
    $player = Player::factory()->create(['futbolfantasy_id' => 7257]);
    FixtureLineupProbability::factory()->for($player)->create();

    expect($player->refresh()->futbolfantasy_id)->toBe(7257)
        ->and($player->lineupProbabilities)->toHaveCount(1);
});

test('two players cannot share a FútbolFantasy id', function (): void {
    Player::factory()->create(['futbolfantasy_id' => 7257]);
    Player::factory()->create(['futbolfantasy_id' => 7257]);
})->throws(QueryException::class);
```

`tests/Unit/Services/FutbolFantasyTeamsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Services\FutbolFantasyTeams;

test('maps all 20 LaLiga teams to a distinct FútbolFantasy slug that has a team code', function (): void {
    expect(FutbolFantasyTeams::SLUGS)->toHaveCount(20)
        ->and(array_unique(FutbolFantasyTeams::SLUGS))->toHaveCount(20)
        ->and(array_keys(FutbolFantasyTeams::CODES))->toEqualCanonicalizing(array_values(FutbolFantasyTeams::SLUGS));
});

test('resolves a team page and the code FútbolFantasy writes for the team', function (): void {
    expect(FutbolFantasyTeams::slugFor(15))->toBe('real-madrid')
        ->and(FutbolFantasyTeams::codeFor(15))->toBe('RMD')
        ->and(FutbolFantasyTeams::codeFor(26))->toBe('DEP')
        ->and(FutbolFantasyTeams::codeFor(12))->toBe('MLG')
        ->and(FutbolFantasyTeams::pageUrlFor(2))->toBe('https://www.futbolfantasy.com/laliga/equipos/atletico');
});

test('knows nothing about a team outside the map', function (): void {
    expect(FutbolFantasyTeams::slugFor(999))->toBeNull()
        ->and(FutbolFantasyTeams::codeFor(999))->toBeNull()
        ->and(FutbolFantasyTeams::pageUrlFor(999))->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Models/FixtureLineupProbabilityTest.php tests/Unit/Services/FutbolFantasyTeamsTest.php`
Expected: FAIL with `Class "App\Models\FixtureLineupProbability" not found` and `Class "App\Services\FutbolFantasyTeams" not found`.

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_09_27_100000_add_futbolfantasy_id_to_players_table.php`:

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
            $table->unsignedInteger('futbolfantasy_id')->nullable()->unique()->after('wc26_id');
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->dropUnique(['futbolfantasy_id']);
            $table->dropColumn('futbolfantasy_id');
        });
    }
};
```

`database/migrations/2026_09_27_100100_create_fixture_lineup_probabilities_table.php`:

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
        Schema::create('fixture_lineup_probabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('probability')->nullable();
            $table->boolean('predicted_starter')->default(false);
            $table->boolean('confirmed_starter')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['player_id', 'fixture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_lineup_probabilities');
    }
};
```

- [ ] **Step 4: Write the model and its factory**

`app/Models/FixtureLineupProbability.php`:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FixtureLineupProbabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FútbolFantasy's view of one player for one fixture: the last predicted
 * start probability, whether he was in FF's probable XI, and — once FF
 * publishes "Alineación confirmada" — whether he starts. Upserted by
 * season:sync-start-probabilities and never deleted after kickoff: the last
 * predicted % backs the "Sorpresa / Se cae · era N %" marks and is history.
 *
 * @property-read int $id
 * @property-read int $player_id
 * @property-read int $fixture_id
 * @property-read int|null $probability 0–100; null when FF gave no % (pre-season, or confirmed before we saw a %)
 * @property-read bool $predicted_starter In FF's probable XI (`data-onceFF="titular"`)
 * @property-read bool|null $confirmed_starter From FF's "Alineación confirmada": true = Titular, false = Suplente, null = not confirmed
 * @property-read CarbonImmutable $fetched_at
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(FixtureLineupProbabilityFactory::class)]
#[Table(name: 'fixture_lineup_probabilities', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['player_id', 'fixture_id', 'probability', 'predicted_starter', 'confirmed_starter', 'fetched_at'])]
class FixtureLineupProbability extends Model
{
    /** @use HasFactory<FixtureLineupProbabilityFactory> */
    use HasFactory;

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

    /** @var array<string, mixed> */
    protected $attributes = [
        'predicted_starter' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'player_id' => 'int',
            'fixture_id' => 'int',
            'probability' => 'int',
            'predicted_starter' => 'bool',
            'confirmed_starter' => 'bool',
            'fetched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
```

`database/factories/FixtureLineupProbabilityFactory.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\FixtureLineupProbability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureLineupProbability>
 */
class FixtureLineupProbabilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'fixture_id' => Fixture::factory(),
            'probability' => $this->faker->numberBetween(0, 100),
            'predicted_starter' => $this->faker->boolean(),
            'confirmed_starter' => null,
            'fetched_at' => now(),
        ];
    }
}
```

- [ ] **Step 5: Extend `Player`**

In `app/Models/Player.php`:

1. In the class docblock, after ` * @property-read int|null $wc26_id`, add:

```php
 * @property-read int|null $futbolfantasy_id FútbolFantasy's player id (`jugador_{id}`), stored by season:sync-start-probabilities after the first successful link.
```

and after the `$api_ownership_activity` line (the last `@property`), add:

```php
 * @property array{fixture_id: int, week_number: int, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, confirmed_source: 'worldcup26'|'futbolfantasy'|null, is_stale: bool, fetched_at: string|null, source_url: string, team_short_name: string}|null $next_start Start probability (or confirmed lineup) for the player's team's next fixture. Only set on the manager ficha (SeasonManagersController); not a database column.
```

2. Replace the `#[Fillable(...)]` line with:

```php
#[Fillable(['fantasy_id', 'wc26_id', 'futbolfantasy_id', 'nickname', 'status', 'image', 'team_id'])]
```

3. Add the relation after `marketPlayer()`:

```php
    /** @return HasMany<FixtureLineupProbability, $this> */
    public function lineupProbabilities(): HasMany
    {
        return $this->hasMany(FixtureLineupProbability::class);
    }
```

4. In `casts()`, after `'wc26_id' => 'int',` add `'futbolfantasy_id' => 'int',`.

- [ ] **Step 6: Write the team map**

`app/Services/FutbolFantasyTeams.php`. The slugs are taken from the cached FF pages and LaLigaApp's list. The codes are the ones FF writes in `data-rival`/`data-abrLocal`, and three of them differ from our short names: RMD/RMA, DEP/RCD, MLG/MGA.

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Which FútbolFantasy team page belongs to each of our teams, and the team
 * code FF writes in `data-rival` (the sanity check that a page is about the
 * fixture we store it on). Keyed by our teams.fantasy_id (LaLiga Fantasy's
 * team id) — a fixed map, like PLAYER_MAP in season:link-match-data-players.
 * A season team missing here is reported by season:sync-start-probabilities.
 */
final class FutbolFantasyTeams
{
    public const string TEAM_PAGE_URL = 'https://www.futbolfantasy.com/laliga/equipos/';

    /**
     * teams.fantasy_id => FútbolFantasy team page slug.
     *
     * @var array<int, string>
     */
    public const array SLUGS = [
        21 => 'alaves', // ALA - Deportivo Alavés
        3 => 'athletic', // ATH - Athletic Club
        2 => 'atletico', // ATM - Atlético de Madrid
        4 => 'barcelona', // BAR - FC Barcelona
        5 => 'betis', // BET - Real Betis
        6 => 'celta', // CEL - Celta
        26 => 'deportivo', // RCD - RC Deportivo
        7 => 'elche', // ELC - Elche CF
        8 => 'espanyol', // ESP - RCD Espanyol
        9 => 'getafe', // GET - Getafe CF
        11 => 'levante', // LEV - Levante UD
        12 => 'malaga', // MGA - Málaga CF
        13 => 'osasuna', // OSA - C.A. Osasuna
        49 => 'racing', // RAC - R. Racing Club
        14 => 'rayo-vallecano', // RAY - Rayo Vallecano
        15 => 'real-madrid', // RMA - Real Madrid
        16 => 'real-sociedad', // RSO - Real Sociedad
        17 => 'sevilla', // SEV - Sevilla FC
        18 => 'valencia', // VAL - Valencia CF
        20 => 'villarreal', // VIL - Villarreal CF
    ];

    /**
     * FútbolFantasy slug => the team code FF writes in `data-rival` / `data-equipo`.
     *
     * @var array<string, string>
     */
    public const array CODES = [
        'alaves' => 'ALA',
        'athletic' => 'ATH',
        'atletico' => 'ATM',
        'barcelona' => 'BAR',
        'betis' => 'BET',
        'celta' => 'CEL',
        'deportivo' => 'DEP',
        'elche' => 'ELC',
        'espanyol' => 'ESP',
        'getafe' => 'GET',
        'levante' => 'LEV',
        'malaga' => 'MLG',
        'osasuna' => 'OSA',
        'racing' => 'RAC',
        'rayo-vallecano' => 'RAY',
        'real-madrid' => 'RMD',
        'real-sociedad' => 'RSO',
        'sevilla' => 'SEV',
        'valencia' => 'VAL',
        'villarreal' => 'VIL',
    ];

    public static function slugFor(int $fantasyId): ?string
    {
        return self::SLUGS[$fantasyId] ?? null;
    }

    public static function codeFor(int $fantasyId): ?string
    {
        $slug = self::slugFor($fantasyId);

        return $slug === null ? null : (self::CODES[$slug] ?? null);
    }

    public static function pageUrlFor(int $fantasyId): ?string
    {
        $slug = self::slugFor($fantasyId);

        return $slug === null ? null : self::TEAM_PAGE_URL.$slug;
    }
}
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `herd php artisan test --compact tests/Feature/Models/FixtureLineupProbabilityTest.php tests/Unit/Services/FutbolFantasyTeamsTest.php tests/Feature/Models/PlayerTest.php`
Expected: PASS.

- [ ] **Step 8: Migrate the local database, format, analyse**

Run: `herd php artisan migrate --no-interaction`
Expected: both migrations run.

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_27_100000_add_futbolfantasy_id_to_players_table.php database/migrations/2026_09_27_100100_create_fixture_lineup_probabilities_table.php app/Models/FixtureLineupProbability.php database/factories/FixtureLineupProbabilityFactory.php app/Models/Player.php app/Services/FutbolFantasyTeams.php tests/Feature/Models/FixtureLineupProbabilityTest.php tests/Unit/Services/FutbolFantasyTeamsTest.php
git commit -m "feat: store start probabilities per player and fixture" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: FútbolFantasy team page parser

**Files:**
- Create: `app/Services/FutbolFantasyPlayer.php`, `app/Services/FutbolFantasyTeamPage.php`, `app/Services/FutbolFantasyPageException.php`, `app/Services/FutbolFantasyTeamPageParser.php`
- Create: `tests/Fixtures/futbolfantasy/real-madrid-posible.html`, `tests/Fixtures/futbolfantasy/real-madrid-confirmada.html`
- Test: `tests/Unit/Services/FutbolFantasyTeamPageParserTest.php`

**Markup facts the parser relies on**, checked on the cached pages for RMA, RAC, BAR, ALA, ATM, BET, ELC and LEV:
- The lineup is the first `section.alineacion_wrapper` that is **not** `.observaciones`. The `.observaciones` section is "Once tipo y mapa rotacional", whose blocks have no `data-probabilidad`.
- The heading always contains **both** `span.posible` ("Posible alineación") and a hidden `span.pasada` ("Alineación confirmada"). The jornada is `span.jornada` ("8"). Read the state from `span.posible` only.
- Every player appears twice. The pitch block is a `div.jugador_{id}.camiseta-wrapper` with `data-onceFF` and `data-posicionLaLigaFantasy`, and its data attributes sit on the inner `a.camiseta`. The list block is a `div.jugador_{id}.tipo_lista` with the attributes on the div itself and `isSuplente` on bench players.
- The name is `.truncate-name` inside `.juggadores a.juggador.pos-0`. Some shirts have `href="#"` plus an alternative `pos-1` player, so take the slug from the first `a[href*="/jugadores/"]`.

**Interfaces:**
- Produces:
  - `final readonly class App\Services\FutbolFantasyPlayer(int $futbolfantasyId, string $name, string $slug, ?int $probability, ?bool $confirmedStarter, bool $predictedStarter, string $rivalCode, int $marketValue, int $totalPoints, ?App\Enums\PlayerPosition $position)` + `withFallback(self $other): self`.
  - `final readonly class App\Services\FutbolFantasyTeamPage(int $weekNumber, bool $confirmed, list<FutbolFantasyPlayer> $players)` + `rivalCode(): string` (first non-empty `data-rival`, `''` if none).
  - `class App\Services\FutbolFantasyPageException extends RuntimeException`.
  - `App\Services\FutbolFantasyTeamPageParser::parse(string $html): FutbolFantasyTeamPage`. It throws `FutbolFantasyPageException` when there is no lineup section, no jornada, or no players.

- [ ] **Step 1: Add the HTML fixtures**

`tests/Fixtures/futbolfantasy/real-madrid-posible.html`. It is trimmed from the real page of 27/09/2026 and keeps its structure: the duplicate list blocks, a `href="#"` shirt with an alternative player, a missing %, and the "once tipo" section, which must be ignored.

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Real Madrid - Plantilla, alineaciones y noticias | FútbolFantasy</title>
</head>
<body>
<section class="container equipo my-3 px-0">
    <h1>Real Madrid</h1>
</section>
<div class="row">
    <div class="col-12 col-md-6 order-0 order-md-0 pl-lg-0 pr-lg-1 p-0 pl-lg-2">
        <section data-equipo="15" class="mod alineacion_wrapper block-new-only-header" data-prox="22500">
            <header class="title pt-0">
                <div class="row">
                    <h2 class="col-12 d-flex flex-row px-0 main title mb-0 py-2">
                        <span class="ml-auto posible mt-auto mb-0 mr-0 pl-0 text-center">
                            Posible alineación
                        </span>
                        <span class="pasada ml-auto mt-auto mb-0 mx-0 text-center">Alineación confirmada</span>
                        <span class="mb-0 mr-auto ml-0 mt-0">
                            <span class="jornada_label my-auto ml-1">J</span><span class="jornada my-auto">8</span>
                        </span>
                    </h2>
                </div>
            </header>
            <div class="alineacion px-0">
                <div class="jugadores-titulares-22500 mod lesionados mb-0">
                    <div class="jugador_59 tipo_campo portero    camiseta-wrapper" style="left: 50%; top: 88%" data-index="0" data-posicion="Portero" data-posicionLaLigaFantasy="Portero" data-onceFF="titular" data-onceFF-x="50%" data-onceFF-y="88%">
                        <a class="camiseta " data-totalPartidosJugados="7" data-probabilidad="95%" data-lesion="-1" data-sancionado="0" data-nodisponible="0" data-estado="0" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="56825743" data-valor-diff-laliga-fantasy="120000" data-puntos-totales-laliga-fantasy="41" data-puntos-media-laliga-fantasy="5.9" href="https://www.futbolfantasy.com/jugadores/thibaut-courtois/laliga-26-27">
                            <span class="view probabilidad probabilidad-widget force-block d-block mb-1"><span class="mx-auto prob-5">95%</span></span>
                            <div class="fotocontainer laliga"><img alt="Thibaut Courtois" class="img lazyload fotozoom laliga" src="https://static.futbolfantasy.com/uploads/images/camisetas/2027_15_local_campo.png"></div>
                        </a>
                        <div class="juggadores">
                            <a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/thibaut-courtois/laliga-26-27">
                                <span class="truncate-name mx-auto">Courtois</span>
                            </a>
                        </div>
                    </div>
                    <div class="jugador_6055 tipo_campo campo    camiseta-wrapper" style="left: 89%; top: 66%" data-index="1" data-posicion="Defensa" data-posicionLaLigaFantasy="Defensa" data-onceFF="titular" data-onceFF-x="89%" data-onceFF-y="66%">
                        <a class="camiseta " data-totalPartidosJugados="6" data-probabilidad="70%" data-lesion="-1" data-sancionado="0" data-nodisponible="0" data-estado="0" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="26446951" data-valor-diff-laliga-fantasy="-561444" data-puntos-totales-laliga-fantasy="12" data-puntos-media-laliga-fantasy="2" href="https://www.futbolfantasy.com/jugadores/denzel-dumfries/laliga-26-27">
                            <span class="view probabilidad probabilidad-widget force-block d-block mb-1"><span class="mx-auto prob-3">70%</span></span>
                            <div class="fotocontainer laliga"><img alt="Denzel Dumfries" class="img lazyload fotozoom laliga" src="https://static.futbolfantasy.com/uploads/images/camisetas/2027_15_local_campo.png"></div>
                        </a>
                        <div class="juggadores">
                            <a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/denzel-dumfries/laliga-26-27">
                                <span class="truncate-name mx-auto">Dumfries</span>
                            </a>
                        </div>
                    </div>
                    <div class="jugador_5565 tipo_campo campo    camiseta-wrapper" style="left: 20%; top: 18%" data-index="2" data-posicion="Delantero" data-posicionLaLigaFantasy="Delantero" data-onceFF="titular" data-onceFF-x="20%" data-onceFF-y="18%">
                        <a class="camiseta " data-totalPartidosJugados="7" data-probabilidad="60%" data-lesion="-1" data-sancionado="0" data-nodisponible="0" data-estado="0" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="94433940" data-valor-diff-laliga-fantasy="0" data-puntos-totales-laliga-fantasy="50" data-puntos-media-laliga-fantasy="7.1" href="#">
                            <span class="view probabilidad probabilidad-widget force-block d-block mb-1"><span class="mx-auto prob-2">60%</span></span>
                            <div class="fotocontainer laliga"><img alt="Vinícius Júnior" class="img lazyload fotozoom laliga" src="https://static.futbolfantasy.com/uploads/images/camisetas/2027_15_local_campo.png"></div>
                        </a>
                        <div class="juggadores">
                            <a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/vinicius-junior/laliga-26-27">
                                <span class="truncate-name mx-auto">Vinicius</span>
                            </a>
                            <a class="juggador pos-1 flex-column" href="https://www.futbolfantasy.com/jugadores/yan-diomande/laliga-26-27">
                                <span class="truncate-name mx-auto">Y. Diomande</span>
                            </a>
                        </div>
                    </div>
                    <div class="jugador_13564 tipo_campo campo   supl-148   camiseta-wrapper" data-index="12" data-posicion="Delantero" data-posicionLaLigaFantasy="Delantero" data-onceFF="suplente">
                        <a class="camiseta " data-totalPartidosJugados="1" data-probabilidad="10%" data-lesion="-1" data-sancionado="0" data-nodisponible="0" data-estado="0" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="2713822" data-valor-diff-laliga-fantasy="0" data-puntos-totales-laliga-fantasy="1" data-puntos-media-laliga-fantasy="1" href="https://www.futbolfantasy.com/jugadores/endrick/laliga-26-27">
                            <span class="view probabilidad probabilidad-widget force-block d-block mb-1"><span class="mx-auto prob-0">10%</span></span>
                        </a>
                        <div class="juggadores">
                            <a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/endrick/laliga-26-27">
                                <span class="truncate-name mx-auto">Endrick</span>
                            </a>
                        </div>
                    </div>
                    <div class="jugador_17000 tipo_campo campo   supl-250   camiseta-wrapper" data-index="13" data-posicion="Mediocampista" data-posicionLaLigaFantasy="Mediocampista" data-onceFF="suplente">
                        <a class="camiseta " data-totalPartidosJugados="0" data-probabilidad="" data-lesion="-1" data-sancionado="0" data-nodisponible="0" data-estado="0" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="30000000" data-valor-diff-laliga-fantasy="0" data-puntos-totales-laliga-fantasy="0" data-puntos-media-laliga-fantasy="0" href="https://www.futbolfantasy.com/jugadores/franco-mastantuono/laliga-26-27">
                        </a>
                        <div class="juggadores">
                            <a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/franco-mastantuono/laliga-26-27">
                                <span class="truncate-name mx-auto">Mastantuono</span>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="jugadores-lista-22500">
                    <div class="jugador_59 jugador tipo_lista d-none block-new " data-campo-posicion="88050" data-probabilidad="95%" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="56825743" data-puntos-totales-laliga-fantasy="41">
                        <a class="jugador my-auto" href="https://www.futbolfantasy.com/jugadores/thibaut-courtois/laliga-26-27"><span class="nombre">Courtois</span></a>
                    </div>
                    <div class="jugador_6055 jugador tipo_lista d-none block-new " data-campo-posicion="66089" data-probabilidad="70%" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="26446951" data-puntos-totales-laliga-fantasy="12">
                        <a class="jugador my-auto" href="https://www.futbolfantasy.com/jugadores/denzel-dumfries/laliga-26-27"><span class="nombre">Dumfries</span></a>
                    </div>
                    <div class="jugador_13564 jugador tipo_lista d-none block-new  isSuplente " data-campo-posicion="0" data-probabilidad="10%" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="2713822" data-puntos-totales-laliga-fantasy="1">
                        <a class="jugador my-auto" href="https://www.futbolfantasy.com/jugadores/endrick/laliga-26-27"><span class="nombre">Endrick</span></a>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
<section class="mod alineacion_wrapper observaciones order-5 order-lg-5">
    <header class="title text-center text-md-left w-100">Once tipo y mapa rotacional</header>
    <div class="alineacion px-0">
        <div class="jugador_59 portero    camiseta-wrapper" data-onceFF="titular">
            <a class="camiseta" href="https://www.futbolfantasy.com/jugadores/thibaut-courtois/laliga-26-27"><span class="truncate-name mx-auto">Courtois</span></a>
        </div>
        <div class="jugador_3758 campo    camiseta-wrapper" data-onceFF="titular">
            <a class="camiseta" href="https://www.futbolfantasy.com/jugadores/federico-valverde/laliga-26-27"><span class="truncate-name mx-auto">Valverde</span></a>
        </div>
    </div>
</section>
</body>
</html>
```

`tests/Fixtures/futbolfantasy/real-madrid-confirmada.html`. It is the same page once FF has published the official lineup. `span.posible` changes and every value becomes `Titular`/`Suplente`. Endrick starts, which is a surprise, and Vinicius drops out:

```html
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Real Madrid - Plantilla, alineaciones y noticias | FútbolFantasy</title>
</head>
<body>
<div class="row">
    <div class="col-12 col-md-6 order-0 order-md-0 pl-lg-0 pr-lg-1 p-0 pl-lg-2">
        <section data-equipo="15" class="mod alineacion_wrapper block-new-only-header" data-prox="22500">
            <header class="title pt-0">
                <div class="row">
                    <h2 class="col-12 d-flex flex-row px-0 main title mb-0 py-2">
                        <span class="ml-auto posible mt-auto mb-0 mr-0 pl-0 text-center">
                            Alineación confirmada
                        </span>
                        <span class="pasada ml-auto mt-auto mb-0 mx-0 text-center">Alineación confirmada</span>
                        <span class="mb-0 mr-auto ml-0 mt-0">
                            <span class="jornada_label my-auto ml-1">J</span><span class="jornada my-auto">8</span>
                        </span>
                    </h2>
                </div>
            </header>
            <div class="alineacion px-0">
                <div class="jugadores-titulares-22500 mod lesionados mb-0">
                    <div class="jugador_59 tipo_campo portero    camiseta-wrapper" data-posicionLaLigaFantasy="Portero" data-onceFF="titular">
                        <a class="camiseta " data-probabilidad="Titular" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="56825743" data-puntos-totales-laliga-fantasy="41" href="https://www.futbolfantasy.com/jugadores/thibaut-courtois/laliga-26-27"></a>
                        <div class="juggadores"><a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/thibaut-courtois/laliga-26-27"><span class="truncate-name mx-auto">Courtois</span></a></div>
                    </div>
                    <div class="jugador_6055 tipo_campo campo    camiseta-wrapper" data-posicionLaLigaFantasy="Defensa" data-onceFF="titular">
                        <a class="camiseta " data-probabilidad="Titular" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="26446951" data-puntos-totales-laliga-fantasy="12" href="https://www.futbolfantasy.com/jugadores/denzel-dumfries/laliga-26-27"></a>
                        <div class="juggadores"><a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/denzel-dumfries/laliga-26-27"><span class="truncate-name mx-auto">Dumfries</span></a></div>
                    </div>
                    <div class="jugador_5565 tipo_campo campo   supl-44   camiseta-wrapper" data-posicionLaLigaFantasy="Delantero" data-onceFF="titular">
                        <a class="camiseta " data-probabilidad="Suplente" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="94433940" data-puntos-totales-laliga-fantasy="50" href="#"></a>
                        <div class="juggadores"><a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/vinicius-junior/laliga-26-27"><span class="truncate-name mx-auto">Vinicius</span></a></div>
                    </div>
                    <div class="jugador_13564 tipo_campo campo    camiseta-wrapper" data-posicionLaLigaFantasy="Delantero" data-onceFF="suplente">
                        <a class="camiseta " data-probabilidad="Titular" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="2713822" data-puntos-totales-laliga-fantasy="1" href="https://www.futbolfantasy.com/jugadores/endrick/laliga-26-27"></a>
                        <div class="juggadores"><a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/endrick/laliga-26-27"><span class="truncate-name mx-auto">Endrick</span></a></div>
                    </div>
                    <div class="jugador_17000 tipo_campo campo   supl-250   camiseta-wrapper" data-posicionLaLigaFantasy="Mediocampista" data-onceFF="suplente">
                        <a class="camiseta " data-probabilidad="Suplente" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="30000000" data-puntos-totales-laliga-fantasy="0" href="https://www.futbolfantasy.com/jugadores/franco-mastantuono/laliga-26-27"></a>
                        <div class="juggadores"><a class="juggador pos-0 flex-column" href="https://www.futbolfantasy.com/jugadores/franco-mastantuono/laliga-26-27"><span class="truncate-name mx-auto">Mastantuono</span></a></div>
                    </div>
                </div>
                <div class="jugadores-lista-22500">
                    <div class="jugador_59 jugador tipo_lista d-none block-new " data-probabilidad="Titular" data-rival="VIL" data-equipo="RMD" data-valor-laliga-fantasy="56825743" data-puntos-totales-laliga-fantasy="41">
                        <a class="jugador my-auto" href="https://www.futbolfantasy.com/jugadores/thibaut-courtois/laliga-26-27"><span class="nombre">Courtois</span></a>
                    </div>
                </div>
            </div>
        </section>
    </div>
</div>
</body>
</html>
```

- [ ] **Step 2: Write the failing test**

`tests/Unit/Services/FutbolFantasyTeamPageParserTest.php` (Unit tests in this repo omit `declare`; follow `tests/Unit/Services/MaxBidFormulaTest.php`):

```php
<?php

use App\Enums\PlayerPosition;
use App\Services\FutbolFantasyPageException;
use App\Services\FutbolFantasyPlayer;
use App\Services\FutbolFantasyTeamPage;
use App\Services\FutbolFantasyTeamPageParser;

function parsedFutbolFantasyPage(string $name): FutbolFantasyTeamPage
{
    return (new FutbolFantasyTeamPageParser)->parse((string) file_get_contents(__DIR__."/../../Fixtures/futbolfantasy/{$name}.html"));
}

/**
 * @return array<int, FutbolFantasyPlayer>
 */
function parsedPlayersById(FutbolFantasyTeamPage $page): array
{
    $byId = [];

    foreach ($page->players as $player) {
        $byId[$player->futbolfantasyId] = $player;
    }

    return $byId;
}

/**
 * The smallest page the parser accepts, wrapping the given lineup blocks.
 */
function lineupSectionHtml(string $heading, string $blocks): string
{
    return '<!DOCTYPE html><html><body><section class="mod alineacion_wrapper block-new-only-header"><header><h2>'.$heading.'</h2></header>'.$blocks.'</section></body></html>';
}

test('reads the jornada of a probable lineup', function (): void {
    $page = parsedFutbolFantasyPage('real-madrid-posible');

    expect($page->weekNumber)->toBe(8)
        ->and($page->confirmed)->toBeFalse()
        ->and($page->rivalCode())->toBe('VIL');
});

test('keeps each player once although the page prints him twice, and ignores the "once tipo" section', function (): void {
    $page = parsedFutbolFantasyPage('real-madrid-posible');

    expect(array_map(fn (FutbolFantasyPlayer $player): int => $player->futbolfantasyId, $page->players))
        ->toBe([59, 6055, 5565, 13564, 17000]);
});

test('reads every field of a player block', function (): void {
    $courtois = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[59];

    expect($courtois->name)->toBe('Courtois')
        ->and($courtois->slug)->toBe('thibaut-courtois')
        ->and($courtois->probability)->toBe(95)
        ->and($courtois->confirmedStarter)->toBeNull()
        ->and($courtois->predictedStarter)->toBeTrue()
        ->and($courtois->rivalCode)->toBe('VIL')
        ->and($courtois->marketValue)->toBe(56825743)
        ->and($courtois->totalPoints)->toBe(41)
        ->and($courtois->position)->toBe(PlayerPosition::Goalkeeper);
});

test('takes the name and slug of the slot\'s own player when the shirt links nowhere', function (): void {
    $vinicius = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[5565];

    expect($vinicius->name)->toBe('Vinicius')
        ->and($vinicius->slug)->toBe('vinicius-junior')
        ->and($vinicius->position)->toBe(PlayerPosition::Striker);
});

test('reads a bench player out of FútbolFantasy\'s probable XI', function (): void {
    $endrick = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[13564];

    expect($endrick->probability)->toBe(10)
        ->and($endrick->predictedStarter)->toBeFalse();
});

test('leaves the probability empty when FútbolFantasy gives none', function (): void {
    $mastantuono = parsedPlayersById(parsedFutbolFantasyPage('real-madrid-posible'))[17000];

    expect($mastantuono->probability)->toBeNull()
        ->and($mastantuono->confirmedStarter)->toBeNull()
        ->and($mastantuono->name)->toBe('Mastantuono');
});

test('reads a confirmed lineup as Titular / Suplente with no probability', function (): void {
    $page = parsedFutbolFantasyPage('real-madrid-confirmada');
    $players = parsedPlayersById($page);

    expect($page->weekNumber)->toBe(8)
        ->and($page->confirmed)->toBeTrue()
        ->and($players[59]->confirmedStarter)->toBeTrue()
        ->and($players[59]->probability)->toBeNull()
        ->and($players[5565]->confirmedStarter)->toBeFalse()
        ->and($players[5565]->predictedStarter)->toBeTrue()
        ->and($players[13564]->confirmedStarter)->toBeTrue()
        ->and($players[13564]->predictedStarter)->toBeFalse();
});

test('reads Titular / Suplente whatever their case and spacing', function (): void {
    $html = lineupSectionHtml(
        '<span class="posible">Posible alineación</span><span class="jornada">9</span>',
        '<div class="jugador_1 tipo_lista" data-probabilidad=" titular " data-rival="BAR"></div>'
            .'<div class="jugador_2 tipo_lista" data-probabilidad="SUPLENTE" data-rival="BAR"></div>',
    );

    $players = parsedPlayersById((new FutbolFantasyTeamPageParser)->parse($html));

    expect($players[1]->confirmedStarter)->toBeTrue()
        ->and($players[2]->confirmedStarter)->toBeFalse();
});

test('rejects a page without a jornada in its lineup heading', function (): void {
    (new FutbolFantasyTeamPageParser)->parse(lineupSectionHtml(
        '<span class="posible">Posible alineación</span>',
        '<div class="jugador_1 tipo_lista" data-probabilidad="80%"></div>',
    ));
})->throws(FutbolFantasyPageException::class, 'jornada');

test('rejects a page without a lineup section', function (): void {
    (new FutbolFantasyTeamPageParser)->parse('<!DOCTYPE html><html><body><p>Mantenimiento</p></body></html>');
})->throws(FutbolFantasyPageException::class, 'lineup');

test('rejects a lineup without players', function (): void {
    (new FutbolFantasyTeamPageParser)->parse(lineupSectionHtml(
        '<span class="posible">Posible alineación</span><span class="jornada">8</span>',
        '',
    ));
})->throws(FutbolFantasyPageException::class, 'no players');
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `herd php artisan test --compact tests/Unit/Services/FutbolFantasyTeamPageParserTest.php`
Expected: FAIL with `Class "App\Services\FutbolFantasyTeamPageParser" not found`.

- [ ] **Step 4: Write the DTOs and the exception**

`app/Services/FutbolFantasyPageException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * A FútbolFantasy team page we can't read a lineup from — the sync skips the
 * team and leaves its rows untouched.
 */
class FutbolFantasyPageException extends RuntimeException {}
```

`app/Services/FutbolFantasyPlayer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerPosition;

/**
 * One player block of a FútbolFantasy team page.
 */
final readonly class FutbolFantasyPlayer
{
    /**
     * @param  int  $futbolfantasyId  FF's player id (`jugador_{id}`)
     * @param  string  $name  FF's short name (`.truncate-name`), '' if the block has none
     * @param  string  $slug  FF's player slug (`/jugadores/{slug}/…`), '' if none
     * @param  int|null  $probability  `data-probabilidad` "NN%" as 0–100; null when there is no %
     * @param  bool|null  $confirmedStarter  `data-probabilidad` "Titular" (true) / "Suplente" (false); null while only predicted
     * @param  bool  $predictedStarter  in FF's probable XI (`data-onceFF="titular"`)
     * @param  string  $rivalCode  `data-rival`, e.g. "VIL"
     * @param  int  $marketValue  `data-valor-laliga-fantasy`, LaLiga Fantasy's market value to the euro
     * @param  int  $totalPoints  `data-puntos-totales-laliga-fantasy`
     * @param  PlayerPosition|null  $position  `data-posicionLaLigaFantasy`
     */
    public function __construct(
        public int $futbolfantasyId,
        public string $name,
        public string $slug,
        public ?int $probability,
        public ?bool $confirmedStarter,
        public bool $predictedStarter,
        public string $rivalCode,
        public int $marketValue,
        public int $totalPoints,
        public ?PlayerPosition $position,
    ) {}

    /**
     * The same player seen in another block of the page (FF prints every
     * player twice — pitch and list): this block's values win, the other
     * only fills what this one lacks.
     */
    public function withFallback(self $other): self
    {
        return new self(
            futbolfantasyId: $this->futbolfantasyId,
            name: $this->name !== '' ? $this->name : $other->name,
            slug: $this->slug !== '' ? $this->slug : $other->slug,
            probability: $this->probability ?? $other->probability,
            confirmedStarter: $this->confirmedStarter ?? $other->confirmedStarter,
            predictedStarter: $this->predictedStarter,
            rivalCode: $this->rivalCode !== '' ? $this->rivalCode : $other->rivalCode,
            marketValue: $this->marketValue > 0 ? $this->marketValue : $other->marketValue,
            totalPoints: $this->totalPoints,
            position: $this->position ?? $other->position,
        );
    }
}
```

`app/Services/FutbolFantasyTeamPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The lineup block of a FútbolFantasy team page: "Posible alineación J{n}"
 * or, once the official lineup is out, "Alineación confirmada J{n}".
 */
final readonly class FutbolFantasyTeamPage
{
    /**
     * @param  list<FutbolFantasyPlayer>  $players  one per FF player id, in page order
     */
    public function __construct(
        public int $weekNumber,
        public bool $confirmed,
        public array $players,
    ) {}

    /**
     * The rival FF says the lineup is for — '' when no block carries one.
     */
    public function rivalCode(): string
    {
        foreach ($this->players as $player) {
            if ($player->rivalCode !== '') {
                return $player->rivalCode;
            }
        }

        return '';
    }
}
```

- [ ] **Step 5: Write the parser**

`app/Services/FutbolFantasyTeamPageParser.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerPosition;
use Dom\Element;
use Dom\HTMLDocument;

/**
 * Reads the lineup block of a FútbolFantasy team page
 * (`/laliga/equipos/{slug}`, server-rendered, ~2.4 MB) with PHP's HTML5
 * parser. Pure: HTML in, FutbolFantasyTeamPage out.
 *
 * - The lineup is the first `section.alineacion_wrapper` that isn't
 *   `.observaciones` ("Once tipo y mapa rotacional").
 * - The jornada is its `span.jornada`; the state is its `span.posible`
 *   ("Posible alineación" / "Alineación confirmada") — the hidden
 *   `span.pasada` always reads "Alineación confirmada", so it's ignored.
 * - Every element with `data-probabilidad` is a player block; its wrapper
 *   is the closest `jugador_{id}` element. FF prints each player twice
 *   (pitch + list), merged by id.
 */
class FutbolFantasyTeamPageParser
{
    /**
     * @throws FutbolFantasyPageException
     */
    public function parse(string $html): FutbolFantasyTeamPage
    {
        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $section = $this->lineupSection($document);

        if ($section === null) {
            throw new FutbolFantasyPageException('no lineup section on the page');
        }

        $weekNumber = $this->weekNumber($section);

        if ($weekNumber === null) {
            throw new FutbolFantasyPageException('no "J{n}" jornada in the lineup heading');
        }

        $players = $this->players($section);

        if ($players === []) {
            throw new FutbolFantasyPageException("no players in the J{$weekNumber} lineup");
        }

        $heading = mb_strtolower(trim((string) $section->querySelector('.posible')?->textContent));
        $anyConfirmed = array_filter($players, fn (FutbolFantasyPlayer $player): bool => $player->confirmedStarter !== null) !== [];

        return new FutbolFantasyTeamPage($weekNumber, str_contains($heading, 'confirmada') || $anyConfirmed, $players);
    }

    private function lineupSection(HTMLDocument $document): ?Element
    {
        foreach ($document->querySelectorAll('section.alineacion_wrapper') as $section) {
            if (!$section->classList->contains('observaciones')) {
                return $section;
            }
        }

        return null;
    }

    private function weekNumber(Element $section): ?int
    {
        $text = trim((string) $section->querySelector('.jornada')?->textContent);

        return ctype_digit($text) ? (int) $text : null;
    }

    /**
     * @return list<FutbolFantasyPlayer>
     */
    private function players(Element $section): array
    {
        /** @var array<int, FutbolFantasyPlayer> $byId */
        $byId = [];

        foreach ($section->querySelectorAll('[data-probabilidad]') as $block) {
            $wrapper = $block->closest('[class*="jugador_"]');

            if ($wrapper === null || preg_match('/(?:^|\s)jugador_(\d+)(?:\s|$)/', (string) $wrapper->getAttribute('class'), $matches) !== 1) {
                continue;
            }

            $player = $this->player((int) $matches[1], $block, $wrapper);
            $byId[$player->futbolfantasyId] = isset($byId[$player->futbolfantasyId])
                ? $byId[$player->futbolfantasyId]->withFallback($player)
                : $player;
        }

        return array_values($byId);
    }

    /**
     * @param  Element  $block  the element carrying the data-* attributes (`a.camiseta` or the list div)
     * @param  Element  $wrapper  the `jugador_{id}` element around it (the block itself for a list div)
     */
    private function player(int $futbolfantasyId, Element $block, Element $wrapper): FutbolFantasyPlayer
    {
        $value = trim((string) $block->getAttribute('data-probabilidad'));

        return new FutbolFantasyPlayer(
            futbolfantasyId: $futbolfantasyId,
            name: trim((string) $wrapper->querySelector('.truncate-name')?->textContent),
            slug: $this->slug($block, $wrapper),
            probability: preg_match('/^(\d{1,3})\s*%$/', $value, $matches) === 1 ? min(100, (int) $matches[1]) : null,
            confirmedStarter: match (mb_strtolower($value)) {
                'titular' => true,
                'suplente' => false,
                default => null,
            },
            predictedStarter: $wrapper->hasAttribute('data-onceff')
                ? $wrapper->getAttribute('data-onceff') === 'titular'
                : !$wrapper->classList->contains('isSuplente'),
            rivalCode: mb_strtoupper(trim((string) $block->getAttribute('data-rival'))),
            marketValue: (int) $block->getAttribute('data-valor-laliga-fantasy'),
            totalPoints: (int) round((float) $block->getAttribute('data-puntos-totales-laliga-fantasy')),
            position: match ($wrapper->getAttribute('data-posicionlaligafantasy')) {
                'Portero' => PlayerPosition::Goalkeeper,
                'Defensa' => PlayerPosition::Defender,
                'Mediocampista' => PlayerPosition::Midfield,
                'Delantero' => PlayerPosition::Striker,
                default => null,
            },
        );
    }

    /**
     * The shirt usually links the player; some link "#" and list the slot's
     * alternatives underneath, the first (`pos-0`) being the player himself.
     */
    private function slug(Element $block, Element $wrapper): string
    {
        $href = (string) $block->getAttribute('href');

        if (!str_contains($href, '/jugadores/')) {
            $href = (string) $wrapper->querySelector('a[href*="/jugadores/"]')?->getAttribute('href');
        }

        return preg_match('~/jugadores/([^/?#]+)~', $href, $matches) === 1 ? $matches[1] : '';
    }
}
```

> HTML5 parsing lowercases attribute names, so read `data-onceff` / `data-posicionlaligafantasy` in lowercase.

- [ ] **Step 6: Run the test to verify it passes**

Run: `herd php artisan test --compact tests/Unit/Services/FutbolFantasyTeamPageParserTest.php`
Expected: PASS (11 tests).

- [ ] **Step 7: Format, analyse, commit**

The parser is exercised on all 20 real pages at the Task 4 checkpoint.

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Services/FutbolFantasyPlayer.php app/Services/FutbolFantasyTeamPage.php app/Services/FutbolFantasyPageException.php app/Services/FutbolFantasyTeamPageParser.php tests/Fixtures/futbolfantasy/real-madrid-posible.html tests/Fixtures/futbolfantasy/real-madrid-confirmada.html tests/Unit/Services/FutbolFantasyTeamPageParserTest.php
git commit -m "feat: parse FútbolFantasy team pages into start probabilities" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 3: Link FútbolFantasy players to ours

**Files:**
- Create: `app/Enums/FutbolFantasyLinkRule.php`
- Create: `app/Services/FutbolFantasyPlayerLinker.php`
- Test: `tests/Feature/Services/FutbolFantasyPlayerLinkerTest.php`

**Interfaces:**
- Consumes: `FutbolFantasyPlayer` (Task 2), `Player::$futbolfantasy_id` (Task 1), `PlayerMarket`, `PlayerSeason`.
- Produces:
  - `enum App\Enums\FutbolFantasyLinkRule: string { StoredId = 'stored_id'; MarketValue = 'market_value'; Name = 'name'; ManualMap = 'manual_map'; }`. The case order is the summary's order.
  - `App\Services\FutbolFantasyPlayerLinker::__construct(array $playerMap = self::PLAYER_MAP)`. Its `public const array PLAYER_MAP` maps an FF id to our `players.fantasy_id`.
  - `FutbolFantasyPlayerLinker::link(Team $team, Season $season, list<FutbolFantasyPlayer> $ffPlayers): array<int, array{player: Player, rule: FutbolFantasyLinkRule}>`. The array is keyed by FF id, and unlinked ids are absent. Rules 2–4 persist `players.futbolfantasy_id`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/Services/FutbolFantasyPlayerLinkerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\FutbolFantasyLinkRule;
use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\FutbolFantasyPlayer;
use App\Services\FutbolFantasyPlayerLinker;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(8)]);
    $this->team = Team::factory()->create();
});

function linkerFfPlayer(int $id, string $name, string $slug = '', int $marketValue = 0, int $totalPoints = 0, ?PlayerPosition $position = null): FutbolFantasyPlayer
{
    return new FutbolFantasyPlayer(
        futbolfantasyId: $id,
        name: $name,
        slug: $slug,
        probability: 50,
        confirmedStarter: null,
        predictedStarter: true,
        rivalCode: 'VIL',
        marketValue: $marketValue,
        totalPoints: $totalPoints,
        position: $position,
    );
}

function marketValueOn(Player $player, int $value, int $daysAgo = 1): void
{
    PlayerMarket::factory()->create([
        'player_id' => $player->id,
        'date' => now()->subDays($daysAgo)->toDateString(),
        'value' => $value,
    ]);
}

test('links by the stored FútbolFantasy id first, even on another team', function (): void {
    $moved = Player::factory()->create(['futbolfantasy_id' => 7257, 'nickname' => 'Pedri']);
    $sameValue = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Otro']);
    marketValueOn($sameValue, 89_817_688);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(7257, 'Pedri', 'pedri-gonzalez', 89_817_688),
    ]);

    expect($links[7257]['player']->id)->toBe($moved->id)
        ->and($links[7257]['rule'])->toBe(FutbolFantasyLinkRule::StoredId)
        ->and($sameValue->refresh()->futbolfantasy_id)->toBeNull();
});

test('links a teammate by the exact market value of the last 3 days and stores the id', function (): void {
    $dumfries = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'D. Dumfries']);
    marketValueOn($dumfries, 26_446_951, daysAgo: 2);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(6055, 'Zzz', 'zzz', 26_446_951),
    ]);

    expect($links[6055]['player']->id)->toBe($dumfries->id)
        ->and($links[6055]['rule'])->toBe(FutbolFantasyLinkRule::MarketValue)
        ->and($dumfries->refresh()->futbolfantasy_id)->toBe(6055);
});

test('ignores market values older than 3 days', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Nadie']);
    marketValueOn($player, 26_446_951, daysAgo: 5);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(6055, 'Zzz', 'zzz', 26_446_951),
    ]);

    expect($links)->toBe([]);
});

test('breaks a market value tie by total points', function (): void {
    $low = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Uno', 'points' => 3]);
    $high = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Dos', 'points' => 12]);
    marketValueOn($low, 150_000);
    marketValueOn($high, 150_000);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(900, 'Zzz', 'zzz', 150_000, totalPoints: 12),
    ]);

    expect($links[900]['player']->id)->toBe($high->id)
        ->and($links[900]['rule'])->toBe(FutbolFantasyLinkRule::MarketValue);
});

test('breaks a market value and points tie by position', function (): void {
    $keeper = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Uno', 'points' => 0, 'position' => PlayerPosition::Goalkeeper]);
    $defender = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Dos', 'points' => 0, 'position' => PlayerPosition::Defender]);
    marketValueOn($keeper, 150_000);
    marketValueOn($defender, 150_000);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(901, 'Zzz', 'zzz', 150_000, totalPoints: 0, position: PlayerPosition::Defender),
    ]);

    expect($links[901]['player']->id)->toBe($defender->id);
});

test('links by normalised name when no market value matches', function (): void {
    $pedri = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Pedri']);
    $martinez = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'T. Martínez']);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(7257, 'Pedri', 'pedri-gonzalez'),
        linkerFfPlayer(3078, 'Toni Martinez', 'antonio-martinez'),
    ]);

    expect($links[7257]['player']->id)->toBe($pedri->id)
        ->and($links[7257]['rule'])->toBe(FutbolFantasyLinkRule::Name)
        ->and($links[3078]['player']->id)->toBe($martinez->id)
        ->and($martinez->refresh()->futbolfantasy_id)->toBe(3078);
});

test('leaves an ambiguous name unlinked', function (): void {
    Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'A. Rodríguez']);
    Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'M. Rodríguez']);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(4000, 'Rodríguez', 'adrian-rodriguez'),
    ]);

    expect($links)->toBe([]);
});

test('never links two FútbolFantasy players to the same one of ours', function (): void {
    $martinez = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'T. Martínez']);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(3078, 'Toni Martinez', 'antonio-martinez'),
        linkerFfPlayer(3079, 'Martinez', 'ismael-martinez'),
    ]);

    expect($links)->toHaveCount(1)
        ->and($links[3078]['player']->id)->toBe($martinez->id)
        ->and($links)->not->toHaveKey(3079);
});

test('never links a player of another team by market value or name', function (): void {
    $elsewhere = Player::factory()->create(['nickname' => 'Pedri']);
    marketValueOn($elsewhere, 89_817_688);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(7257, 'Pedri', 'pedri-gonzalez', 89_817_688),
    ]);

    expect($links)->toBe([]);
});

test('falls back to the manual map for leftovers', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Nombre Raro', 'fantasy_id' => 4242]);

    $links = (new FutbolFantasyPlayerLinker([17000 => 4242]))->link($this->team, $this->season, [
        linkerFfPlayer(17000, 'Mastantuono', 'franco-mastantuono'),
    ]);

    expect($links[17000]['player']->id)->toBe($player->id)
        ->and($links[17000]['rule'])->toBe(FutbolFantasyLinkRule::ManualMap)
        ->and($player->refresh()->futbolfantasy_id)->toBe(17000);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Services/FutbolFantasyPlayerLinkerTest.php`
Expected: FAIL with `Class "App\Enums\FutbolFantasyLinkRule" not found`.

- [ ] **Step 3: Write the enum**

`app/Enums/FutbolFantasyLinkRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which rule linked a FútbolFantasy player to ours — in the order they're tried.
 */
enum FutbolFantasyLinkRule: string
{
    case StoredId = 'stored_id';
    case MarketValue = 'market_value';
    case Name = 'name';
    case ManualMap = 'manual_map';
}
```

- [ ] **Step 4: Write the linker**

`app/Services/FutbolFantasyPlayerLinker.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FutbolFantasyLinkRule;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Links the players of a FútbolFantasy team page to ours. First hit wins:
 *
 * 1. `players.futbolfantasy_id`, stored after the first successful link (any team).
 * 2. Same team + the exact LaLiga Fantasy market value FF prints
 *    (`data-valor-laliga-fantasy`) against our `player_markets` of the last
 *    3 days; a tie is narrowed by total points, then by position.
 * 3. Same team + normalised name: our nickname against FF's short name or
 *    the full name in its slug; failing that, our nickname without its
 *    initial ("T. Martínez" → "martinez") as a whole word of either.
 * 4. The manual map, for leftovers.
 *
 * Rules 2–4 store the FF id on the player, so later syncs link him by rule 1.
 * Each of our players is linked at most once per page, and anything
 * ambiguous stays unlinked — never guessed.
 */
class FutbolFantasyPlayerLinker
{
    private const int MARKET_WINDOW_DAYS = 3;

    /**
     * FútbolFantasy player id => our players.fantasy_id — filled in by hand
     * for the players the sync reports as "Unlinked". Document each entry:
     *   3078 => 2951, // T. Martínez - ALA
     *
     * @var array<int, int>
     */
    public const array PLAYER_MAP = [];

    /**
     * @param  array<int, int>  $playerMap  FútbolFantasy id => players.fantasy_id
     */
    public function __construct(private readonly array $playerMap = self::PLAYER_MAP) {}

    /**
     * @param  list<FutbolFantasyPlayer>  $ffPlayers
     * @return array<int, array{player: Player, rule: FutbolFantasyLinkRule}> keyed by FútbolFantasy id; unlinked ids are absent
     */
    public function link(Team $team, Season $season, array $ffPlayers): array
    {
        $ffIds = array_map(fn (FutbolFantasyPlayer $ffPlayer): int => $ffPlayer->futbolfantasyId, $ffPlayers);

        $storedById = Player::query()
            ->whereIn('futbolfantasy_id', $ffIds)
            ->get()
            ->keyBy('futbolfantasy_id');

        $candidates = Player::query()
            ->where('team_id', $team->id)
            ->whereNull('futbolfantasy_id')
            ->get();

        $candidateIds = $candidates->pluck('id')->all();

        $seasonRows = PlayerSeason::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $candidateIds)
            ->get()
            ->keyBy('player_id');

        /** @var array<int, list<int>> $recentValues */
        $recentValues = [];

        PlayerMarket::query()
            ->whereIn('player_id', $candidateIds)
            ->whereDate('date', '>=', now()->subDays(self::MARKET_WINDOW_DAYS)->toDateString())
            ->get(['player_id', 'value'])
            ->each(function (PlayerMarket $market) use (&$recentValues): void {
                $recentValues[$market->player_id][] = $market->value;
            });

        /** @var array<int, array{player: Player, rule: FutbolFantasyLinkRule}> $links */
        $links = [];
        /** @var array<int, true> $taken our player ids already linked on this page */
        $taken = [];

        // Stored ids first, so a heuristic never claims a player another FF id already owns.
        foreach ($ffPlayers as $ffPlayer) {
            $stored = $storedById->get($ffPlayer->futbolfantasyId);

            if ($stored instanceof Player) {
                $links[$ffPlayer->futbolfantasyId] = ['player' => $stored, 'rule' => FutbolFantasyLinkRule::StoredId];
                $taken[$stored->id] = true;
            }
        }

        foreach ($ffPlayers as $ffPlayer) {
            if (isset($links[$ffPlayer->futbolfantasyId])) {
                continue;
            }

            $free = $candidates->reject(fn (Player $player): bool => isset($taken[$player->id]))->values();

            $link = $this->byMarketValue($ffPlayer, $free, $recentValues, $seasonRows)
                ?? $this->byName($ffPlayer, $free)
                ?? $this->byManualMap($ffPlayer, $taken);

            if ($link === null) {
                continue;
            }

            $link['player']->update(['futbolfantasy_id' => $ffPlayer->futbolfantasyId]);
            $links[$ffPlayer->futbolfantasyId] = $link;
            $taken[$link['player']->id] = true;
        }

        return $links;
    }

    /**
     * @param  Collection<int, Player>  $free
     * @param  array<int, list<int>>  $recentValues
     * @param  Collection<int, PlayerSeason>  $seasonRows  keyed by player id
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function byMarketValue(FutbolFantasyPlayer $ffPlayer, Collection $free, array $recentValues, Collection $seasonRows): ?array
    {
        if ($ffPlayer->marketValue <= 0) {
            return null;
        }

        $matches = $free
            ->filter(fn (Player $player): bool => in_array($ffPlayer->marketValue, $recentValues[$player->id] ?? [], true))
            ->values();
        $matches = $this->narrow($matches, fn (Player $player): bool => $seasonRows->get($player->id)?->points === $ffPlayer->totalPoints);
        $matches = $this->narrow($matches, fn (Player $player): bool => $ffPlayer->position !== null && $seasonRows->get($player->id)?->position === $ffPlayer->position);

        return $this->single($matches, FutbolFantasyLinkRule::MarketValue);
    }

    /**
     * @param  Collection<int, Player>  $free
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function byName(FutbolFantasyPlayer $ffPlayer, Collection $free): ?array
    {
        $names = array_values(array_unique(array_filter(
            [self::normalize($ffPlayer->name), self::normalize(str_replace('-', ' ', $ffPlayer->slug))],
            fn (string $name): bool => $name !== '',
        )));

        if ($names === []) {
            return null;
        }

        $matches = $free
            ->filter(fn (Player $player): bool => in_array(self::normalize($player->nickname), $names, true))
            ->values();

        if ($matches->isEmpty()) {
            $matches = $free
                ->filter(function (Player $player) use ($names): bool {
                    $surname = self::surname($player->nickname);

                    if (mb_strlen($surname) < 3) {
                        return false;
                    }

                    foreach ($names as $name) {
                        if (str_contains(" {$name} ", " {$surname} ")) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values();
        }

        return $this->single($matches, FutbolFantasyLinkRule::Name);
    }

    /**
     * @param  array<int, true>  $taken
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function byManualMap(FutbolFantasyPlayer $ffPlayer, array $taken): ?array
    {
        $fantasyId = $this->playerMap[$ffPlayer->futbolfantasyId] ?? null;

        if ($fantasyId === null) {
            return null;
        }

        $player = Player::query()
            ->where('fantasy_id', $fantasyId)
            ->whereNull('futbolfantasy_id')
            ->first();

        if ($player === null || isset($taken[$player->id])) {
            return null;
        }

        return ['player' => $player, 'rule' => FutbolFantasyLinkRule::ManualMap];
    }

    /**
     * A tie-break: keeps only the matches passing $test, unless that would
     * rule every one of them out.
     *
     * @param  Collection<int, Player>  $matches
     * @param  callable(Player): bool  $test
     * @return Collection<int, Player>
     */
    private function narrow(Collection $matches, callable $test): Collection
    {
        if ($matches->count() <= 1) {
            return $matches;
        }

        $narrowed = $matches->filter($test)->values();

        return $narrowed->isEmpty() ? $matches : $narrowed;
    }

    /**
     * @param  Collection<int, Player>  $matches
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function single(Collection $matches, FutbolFantasyLinkRule $rule): ?array
    {
        $player = $matches->count() === 1 ? $matches->first() : null;

        return $player instanceof Player ? ['player' => $player, 'rule' => $rule] : null;
    }

    /**
     * Lowercase ASCII words: accents, case and punctuation stripped ("Á. Carreras" → "a carreras").
     */
    private static function normalize(string $name): string
    {
        $words = (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name)));

        return trim((string) preg_replace('/\s+/', ' ', $words));
    }

    /**
     * Our nickname without its leading initials ("T. Martínez" → "martinez").
     */
    private static function surname(string $nickname): string
    {
        return (string) preg_replace('/^(?:[a-z] )+/', '', self::normalize($nickname));
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `herd php artisan test --compact tests/Feature/Services/FutbolFantasyPlayerLinkerTest.php`
Expected: PASS (10 tests).

- [ ] **Step 6: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Enums/FutbolFantasyLinkRule.php app/Services/FutbolFantasyPlayerLinker.php tests/Feature/Services/FutbolFantasyPlayerLinkerTest.php
git commit -m "feat: link FútbolFantasy players by stored id, market value, name or manual map" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: Connector, sync command and schedule (ends with a user checkpoint)

**Files:**
- Create: `app/Http/Integrations/FutbolFantasy/FutbolFantasyConnector.php`
- Create: `app/Http/Integrations/FutbolFantasy/Requests/GetTeamPageRequest.php`
- Modify: `config/services.php` (after the `worldcup26` entry)
- Create: `app/Console/Commands/SyncCurrentSeasonStartProbabilities.php`
- Modify: `bootstrap/app.php` (schedule)
- Test: `tests/Unit/Http/Integrations/FutbolFantasy/GetTeamPageRequestTest.php`, `tests/Unit/Http/Integrations/FutbolFantasy/FutbolFantasyConnectorTest.php`, `tests/Feature/Console/Commands/SyncCurrentSeasonStartProbabilitiesTest.php`

**Interfaces:**
- Consumes: `FutbolFantasyTeams::slugFor/codeFor` (Task 1), `FixtureLineupProbability` (Task 1), `FutbolFantasyTeamPageParser::parse` + `FutbolFantasyPageException` (Task 2), `FutbolFantasyPlayerLinker::link` + `FutbolFantasyLinkRule` (Task 3).
- Produces:
  - `FutbolFantasyConnector::getTeamPage(string $slug): Saloon\Http\Response`, `FutbolFantasyConnector::USER_AGENT`.
  - Command `season:sync-start-probabilities {--force}`. It prints, in this exact format:
    - `Teams: {n} fetched, {n} failed, {n} not due, {n} without an upcoming match.`
    - `Players: {n} parsed.`
    - `Linked: {n} by stored id, {n} by market value, {n} by name, {n} by manual map.`
    - `Unlinked ({n}): {name} ({SHORT}, FF {id}), …` (only when there are any)
    - `Missing from the FútbolFantasy team map: {SHORT}, …` (only when there are any)
  - Scheduled every 10 min.

**Due logic.** The team's *next fixture* is its soonest fixture with `state = Scheduled` and `date > now()`. Due means: the next fixture is ≤ 48 h away, **or** no attempt was made in the last 6 h. The last attempt, successful or not, is stored in the cache under `start_probabilities.attempted_at.{team_id}`. A team without a next fixture is never fetched, and that includes after kickoff. `--force` skips the due check.

**Storing.** Load the page's fixture: `week_number = n`, and the team is local or guest. Then check:
- If it is missing → warn.
- If it is no longer `Scheduled`, or its date has passed → warn and **keep the rows as they are**.
- If the FF code of its opponent ≠ the page's `data-rival` (both non-empty) → warn.
- Otherwise, upsert each linked player.

The values written depend on the page:
- **Predicted page:** `probability`, `predicted_starter`, `confirmed_starter = null`, `fetched_at`.
- **Confirmed page (`Titular`/`Suplente`):** only `confirmed_starter` and `fetched_at`. The last predicted % and XI stay.

- [ ] **Step 1: Write the failing request/connector tests**

`tests/Unit/Http/Integrations/FutbolFantasy/GetTeamPageRequestTest.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Integrations\FutbolFantasy\Requests\GetTeamPageRequest;
use Saloon\Enums\Method;

test('requests a team page by its FútbolFantasy slug', function (): void {
    $request = new GetTeamPageRequest('real-madrid');

    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->resolveEndpoint())->toBe('laliga/equipos/real-madrid');
});
```

`tests/Unit/Http/Integrations/FutbolFantasy/FutbolFantasyConnectorTest.php`:

```php
<?php

declare(strict_types=1);

use App\Http\Integrations\FutbolFantasy\FutbolFantasyConnector;

test('identifies itself with an anonymous project User-Agent and asks for gzip', function (): void {
    $headers = (new FutbolFantasyConnector)->headers();

    expect($headers->get('User-Agent'))->toBe(FutbolFantasyConnector::USER_AGENT)
        ->and(FutbolFantasyConnector::USER_AGENT)->toStartWith('ComandoLechuga/')
        ->and(FutbolFantasyConnector::USER_AGENT)->not->toContain('@')
        ->and($headers->get('Accept-Encoding'))->toBe('gzip');
});
```

- [ ] **Step 2: Write the failing command test**

`tests/Feature/Console/Commands/SyncCurrentSeasonStartProbabilitiesTest.php` (command tests in this folder omit `declare`; follow `SyncLiveSeasonMatchDataTest.php`):

```php
<?php

use App\Console\Commands\SyncCurrentSeasonStartProbabilities;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Integrations\FutbolFantasy\FutbolFantasyConnector;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\FixtureLineupProbability;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Sleep;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    Sleep::fake();
});

function futbolFantasyFixtureHtml(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/futbolfantasy/{$name}.html"));
}

/**
 * Real Madrid — the only season team — hosts Villarreal in J8, and four of
 * the five players on the fixture pages are already linked (Mastantuono,
 * FF 17000, isn't one of ours).
 *
 * @return array{season: Season, madrid: Team, villarreal: Team, fixture: Fixture, courtois: Player, dumfries: Player, vinicius: Player, endrick: Player}
 */
function madridHostsVillarrealInWeek8(?CarbonInterface $kickoff = null): array
{
    $season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(8)]);
    $madrid = Team::factory()->create(['fantasy_id' => 15, 'short_name' => 'RMA']);
    $villarreal = Team::factory()->create(['fantasy_id' => 20, 'short_name' => 'VIL']);
    $season->teams()->attach($madrid->id);

    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 8,
        'team_local_id' => $madrid->id,
        'team_guest_id' => $villarreal->id,
        'date' => $kickoff ?? now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);

    $linked = fn (int $futbolfantasyId, string $nickname): Player => Player::factory()->create([
        'team_id' => $madrid->id,
        'futbolfantasy_id' => $futbolfantasyId,
        'nickname' => $nickname,
        'status' => PlayerStatus::Ok,
    ]);

    return [
        'season' => $season,
        'madrid' => $madrid,
        'villarreal' => $villarreal,
        'fixture' => $fixture,
        'courtois' => $linked(59, 'Courtois'),
        'dumfries' => $linked(6055, 'Dumfries'),
        'vinicius' => $linked(5565, 'Vini Jr.'),
        'endrick' => $linked(13564, 'Endrick'),
    ];
}

/**
 * Binds a FútbolFantasy connector answering each team page slug with the given response.
 *
 * @param  array<string, MockResponse>  $pages  slug => response
 */
function fakeFutbolFantasyPages(array $pages): MockClient
{
    $responses = [];

    foreach ($pages as $slug => $response) {
        $responses["*laliga/equipos/{$slug}"] = $response;
    }

    $mockClient = new MockClient($responses);
    app()->instance(FutbolFantasyConnector::class, (new FutbolFantasyConnector)->withMockClient($mockClient));

    return $mockClient;
}

test('stores each linked player\'s probability on the team\'s fixture for the page\'s jornada', function (): void {
    $this->freezeTime();
    ['fixture' => $fixture, 'courtois' => $courtois, 'vinicius' => $vinicius, 'endrick' => $endrick] = madridHostsVillarrealInWeek8();
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $rows = FixtureLineupProbability::query()->where('fixture_id', $fixture->id)->get()->keyBy('player_id');

    expect($rows)->toHaveCount(4)
        ->and($rows[$courtois->id]->probability)->toBe(95)
        ->and($rows[$courtois->id]->predicted_starter)->toBeTrue()
        ->and($rows[$courtois->id]->confirmed_starter)->toBeNull()
        ->and($rows[$courtois->id]->fetched_at->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($rows[$vinicius->id]->probability)->toBe(60)
        ->and($rows[$endrick->id]->probability)->toBe(10)
        ->and($rows[$endrick->id]->predicted_starter)->toBeFalse();
});

test('updates the existing rows instead of adding new ones', function (): void {
    ['fixture' => $fixture, 'courtois' => $courtois] = madridHostsVillarrealInWeek8();
    FixtureLineupProbability::factory()->create([
        'player_id' => $courtois->id,
        'fixture_id' => $fixture->id,
        'probability' => 40,
        'predicted_starter' => false,
    ]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $row = FixtureLineupProbability::query()->where('player_id', $courtois->id)->sole();

    expect(FixtureLineupProbability::query()->count())->toBe(4)
        ->and($row->probability)->toBe(95)
        ->and($row->predicted_starter)->toBeTrue();
});

test('prints a summary of the teams, the parsed and linked players and the unlinked ones', function (): void {
    madridHostsVillarrealInWeek8();
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Teams: 1 fetched, 0 failed, 0 not due, 0 without an upcoming match.')
        ->expectsOutputToContain('Players: 5 parsed.')
        ->expectsOutputToContain('Linked: 4 by stored id, 0 by market value, 0 by name, 0 by manual map.')
        ->expectsOutputToContain('Unlinked (1): Mastantuono (RMA, FF 17000)')
        ->assertSuccessful();
});

test('reports the season teams missing from the FútbolFantasy team map', function (): void {
    ['season' => $season] = madridHostsVillarrealInWeek8();
    $season->teams()->attach(Team::factory()->create(['fantasy_id' => 999, 'short_name' => 'XYZ'])->id);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Missing from the FútbolFantasy team map: XYZ')
        ->assertSuccessful();
});

test('keeps the last predicted % and XI when FútbolFantasy confirms the lineup', function (): void {
    ['courtois' => $courtois, 'vinicius' => $vinicius, 'endrick' => $endrick] = madridHostsVillarrealInWeek8();

    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-confirmada'))]);
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $rows = FixtureLineupProbability::query()->get()->keyBy('player_id');

    expect($rows[$courtois->id]->probability)->toBe(95)
        ->and($rows[$courtois->id]->confirmed_starter)->toBeTrue()
        ->and($rows[$vinicius->id]->probability)->toBe(60)
        ->and($rows[$vinicius->id]->predicted_starter)->toBeTrue()
        ->and($rows[$vinicius->id]->confirmed_starter)->toBeFalse()
        ->and($rows[$endrick->id]->probability)->toBe(10)
        ->and($rows[$endrick->id]->predicted_starter)->toBeFalse()
        ->and($rows[$endrick->id]->confirmed_starter)->toBeTrue();
});

test('leaves the team\'s rows untouched when its page fails', function (): void {
    ['fixture' => $fixture, 'courtois' => $courtois] = madridHostsVillarrealInWeek8();
    $row = FixtureLineupProbability::factory()->create(['player_id' => $courtois->id, 'fixture_id' => $fixture->id, 'probability' => 42]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make('', 500)]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Skipped RMA')
        ->expectsOutputToContain('Teams: 0 fetched, 1 failed')
        ->assertSuccessful();

    expect($row->refresh()->probability)->toBe(42)
        ->and(FixtureLineupProbability::query()->count())->toBe(1);
});

test('leaves the team\'s rows untouched when its page has no players', function (): void {
    ['fixture' => $fixture, 'courtois' => $courtois] = madridHostsVillarrealInWeek8();
    $row = FixtureLineupProbability::factory()->create(['player_id' => $courtois->id, 'fixture_id' => $fixture->id, 'probability' => 42]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(
        '<html><body><section class="mod alineacion_wrapper"><span class="posible">Posible alineación</span><span class="jornada">8</span></section></body></html>',
    )]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('no players in the J8 lineup')
        ->assertSuccessful();

    expect($row->refresh()->probability)->toBe(42);
});

test('skips a page without a jornada in its lineup heading', function (): void {
    madridHostsVillarrealInWeek8();
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(
        '<html><body><section class="mod alineacion_wrapper"><span class="posible">Posible alineación</span><div class="jugador_59 tipo_lista" data-probabilidad="95%"></div></section></body></html>',
    )]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('jornada')
        ->assertSuccessful();

    expect(FixtureLineupProbability::query()->count())->toBe(0);
});

test('stores nothing when the page\'s rival is not the fixture\'s opponent', function (): void {
    ['fixture' => $fixture] = madridHostsVillarrealInWeek8();
    $atletico = Team::factory()->create(['fantasy_id' => 2, 'short_name' => 'ATM']);
    $fixture->update(['team_guest_id' => $atletico->id]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('against VIL, the fixture against ATM')
        ->assertSuccessful();

    expect(FixtureLineupProbability::query()->count())->toBe(0);
});

test('keeps the rows of a jornada that has already kicked off', function (): void {
    ['season' => $season, 'madrid' => $madrid, 'villarreal' => $villarreal, 'fixture' => $fixture, 'courtois' => $courtois]
        = madridHostsVillarrealInWeek8(now()->subHour());
    $fixture->update(['state' => FixtureState::FirstHalf]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 9,
        'team_local_id' => $villarreal->id,
        'team_guest_id' => $madrid->id,
        'date' => now()->addDays(3),
        'state' => FixtureState::Scheduled,
    ]);
    $row = FixtureLineupProbability::factory()->create(['player_id' => $courtois->id, 'fixture_id' => $fixture->id, 'probability' => 42]);
    fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('J8 has already kicked off')
        ->assertSuccessful();

    expect($row->refresh()->probability)->toBe(42)
        ->and(FixtureLineupProbability::query()->count())->toBe(1);
});

test('fetches a team on every run while its next match is within 48 hours', function (): void {
    madridHostsVillarrealInWeek8(now()->addHours(47));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $mockClient->assertSentCount(2);
});

test('fetches a team at most every 6 hours while its next match is further away', function (): void {
    madridHostsVillarrealInWeek8(now()->addDays(4));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('Teams: 0 fetched, 0 failed, 1 not due')
        ->assertSuccessful();
    $mockClient->assertSentCount(1);

    $this->travel(361)->minutes();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    $mockClient->assertSentCount(2);
});

test('never fetches a team whose match has kicked off', function (): void {
    madridHostsVillarrealInWeek8(now()->subMinutes(5));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)
        ->expectsOutputToContain('1 without an upcoming match')
        ->assertSuccessful();

    $mockClient->assertNothingSent();
});

test('--force fetches every team whether it is due or not', function (): void {
    madridHostsVillarrealInWeek8(now()->addDays(4));
    $mockClient = fakeFutbolFantasyPages(['real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible'))]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();
    $this->artisan(SyncCurrentSeasonStartProbabilities::class, ['--force' => true])->assertSuccessful();

    $mockClient->assertSentCount(2);
});

test('pauses 10 to 30 seconds between two page requests', function (): void {
    ['season' => $season, 'villarreal' => $villarreal] = madridHostsVillarrealInWeek8();
    $season->teams()->attach($villarreal->id);
    fakeFutbolFantasyPages([
        'real-madrid' => MockResponse::make(futbolFantasyFixtureHtml('real-madrid-posible')),
        'villarreal' => MockResponse::make('', 500),
    ]);

    $this->artisan(SyncCurrentSeasonStartProbabilities::class)->assertSuccessful();

    Sleep::assertSleptTimes(1);
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds >= 10 && $duration->totalSeconds <= 30);
});

test('is scheduled every ten minutes', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'season:sync-start-probabilities'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/10 * * * *');
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `herd php artisan test --compact tests/Unit/Http/Integrations/FutbolFantasy tests/Feature/Console/Commands/SyncCurrentSeasonStartProbabilitiesTest.php`
Expected: FAIL with `Class "App\Http\Integrations\FutbolFantasy\Requests\GetTeamPageRequest" not found` (and the command class not found).

- [ ] **Step 4: Write the request, the connector and the config**

`app/Http/Integrations/FutbolFantasy/Requests/GetTeamPageRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Integrations\FutbolFantasy\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetTeamPageRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly string $slug) {}

    public function resolveEndpoint(): string
    {
        return "laliga/equipos/{$this->slug}";
    }
}
```

`app/Http/Integrations/FutbolFantasy/FutbolFantasyConnector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Integrations\FutbolFantasy;

use App\Http\Integrations\FutbolFantasy\Requests\GetTeamPageRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;

/**
 * FútbolFantasy's public, server-rendered team pages (~2.4 MB each) — the
 * source of the start probabilities. One attempt per page (no retries);
 * the sync command spaces requests 10–30 s apart.
 */
class FutbolFantasyConnector extends Connector
{
    use HasTimeout;

    /** Anonymous and project-identifying — never personal data. */
    public const string USER_AGENT = 'ComandoLechuga/1.0 (fantasy league viewer)';

    protected float $connectTimeout = 5;

    protected float $requestTimeout = 20;

    public function resolveBaseUrl(): string
    {
        return (string) config('services.futbolfantasy.base_url');
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getTeamPage(string $slug): Response
    {
        return $this->send(new GetTeamPageRequest($slug));
    }

    /**
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'User-Agent' => self::USER_AGENT,
            'Accept' => 'text/html',
            'Accept-Encoding' => 'gzip',
        ];
    }
}
```

In `config/services.php`, after the `'worldcup26' => [...]` entry, add:

```php
    'futbolfantasy' => [
        'base_url' => env('FUTBOLFANTASY_BASE_URL', 'https://www.futbolfantasy.com/'),
    ],
```

- [ ] **Step 5: Write the command**

`app/Console/Commands/SyncCurrentSeasonStartProbabilities.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FixtureState;
use App\Enums\FutbolFantasyLinkRule;
use App\Http\Integrations\FutbolFantasy\FutbolFantasyConnector;
use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\Season;
use App\Models\Team;
use App\Services\FutbolFantasyPageException;
use App\Services\FutbolFantasyPlayer;
use App\Services\FutbolFantasyPlayerLinker;
use App\Services\FutbolFantasyTeamPage;
use App\Services\FutbolFantasyTeamPageParser;
use App\Services\FutbolFantasyTeams;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-start-probabilities {--force : Fetch every team now, ignoring when each one is next due}')]
#[Description('Synchronize every team\'s start probabilities for its next match from its FútbolFantasy team page')]
class SyncCurrentSeasonStartProbabilities extends Command
{
    /** A team whose next match is this close is fetched on every run. */
    private const int EVERY_RUN_WITHIN_HOURS = 48;

    /** Further away, a team is fetched at most this often. */
    private const int FAR_AWAY_EVERY_HOURS = 6;

    private const int MIN_PAUSE_SECONDS = 10;

    private const int MAX_PAUSE_SECONDS = 30;

    private const string ATTEMPTED_AT_CACHE_PREFIX = 'start_probabilities.attempted_at.';

    private int $playersParsed = 0;

    /** @var array<string, int> FutbolFantasyLinkRule value => players linked by it */
    private array $linkedByRule = [];

    /** @var list<string> */
    private array $unlinked = [];

    /**
     * @throws Throwable
     */
    public function handle(FutbolFantasyConnector $connector, FutbolFantasyTeamPageParser $parser, FutbolFantasyPlayerLinker $linker): int
    {
        $season = Season::current();
        $force = (bool) $this->option('force');
        $counts = ['fetched' => 0, 'failed' => 0, 'not_due' => 0, 'no_match' => 0];
        /** @var list<string> $missingFromMap */
        $missingFromMap = [];
        $requests = 0;

        foreach ($season->teams()->orderBy('short_name')->get() as $team) {
            $slug = FutbolFantasyTeams::slugFor($team->fantasy_id);

            if ($slug === null) {
                $missingFromMap[] = $team->short_name;

                continue;
            }

            $nextFixture = $this->nextFixture($team, $season);

            if ($nextFixture === null) {
                $counts['no_match']++;

                continue;
            }

            if (!$force && !$this->isDue($team, $nextFixture)) {
                $counts['not_due']++;

                continue;
            }

            if ($requests > 0) {
                Sleep::for(random_int(self::MIN_PAUSE_SECONDS, self::MAX_PAUSE_SECONDS))->seconds();
            }

            $requests++;
            Cache::forever(self::ATTEMPTED_AT_CACHE_PREFIX.$team->id, now()->getTimestamp());

            try {
                $page = $parser->parse($connector->getTeamPage($slug)->throw()->body());
            } catch (FatalRequestException|RequestException|FutbolFantasyPageException $exception) {
                $counts['failed']++;
                $this->warnAndLog("Skipped {$team->short_name}: {$exception->getMessage()}");

                continue;
            }

            $counts['fetched']++;
            $this->playersParsed += count($page->players);
            $this->store($team, $season, $page, $linker);
        }

        $this->summarize($counts, $missingFromMap);

        return self::SUCCESS;
    }

    /**
     * The team's soonest match that hasn't kicked off — null once the season
     * has none left, which also means a team is never fetched after kickoff.
     */
    private function nextFixture(Team $team, Season $season): ?Fixture
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', now())
            ->where(fn ($query) => $query
                ->where('team_local_id', $team->id)
                ->orWhere('team_guest_id', $team->id))
            ->orderBy('date')
            ->first();
    }

    private function isDue(Team $team, Fixture $nextFixture): bool
    {
        if ($nextFixture->date->lessThanOrEqualTo(now()->addHours(self::EVERY_RUN_WITHIN_HOURS))) {
            return true;
        }

        $attemptedAt = Cache::get(self::ATTEMPTED_AT_CACHE_PREFIX.$team->id);

        return !is_int($attemptedAt) || $attemptedAt <= now()->subHours(self::FAR_AWAY_EVERY_HOURS)->getTimestamp();
    }

    /**
     * Upserts the page onto the team's fixture of that jornada. A confirmed
     * page ("Titular"/"Suplente", no %) only sets `confirmed_starter`: the
     * last predicted % and FF's probable XI stay for the "Sorpresa / Se cae
     * · era N %" marks. Nothing is written for a fixture that has already
     * kicked off — its rows are history.
     *
     * @throws Throwable
     */
    private function store(Team $team, Season $season, FutbolFantasyTeamPage $page, FutbolFantasyPlayerLinker $linker): void
    {
        $fixture = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $page->weekNumber)
            ->where(fn ($query) => $query
                ->where('team_local_id', $team->id)
                ->orWhere('team_guest_id', $team->id))
            ->with(['localTeam', 'guestTeam'])
            ->first();

        if ($fixture === null) {
            $this->warnAndLog("Skipped {$team->short_name}: no J{$page->weekNumber} fixture");

            return;
        }

        if ($fixture->state !== FixtureState::Scheduled || $fixture->date->lessThanOrEqualTo(now())) {
            $this->warnAndLog("Skipped {$team->short_name}: J{$page->weekNumber} has already kicked off, its rows stay as they are");

            return;
        }

        $opponent = $fixture->team_local_id === $team->id ? $fixture->guestTeam : $fixture->localTeam;
        $expectedRival = FutbolFantasyTeams::codeFor($opponent->fantasy_id);
        $pageRival = $page->rivalCode();

        if ($expectedRival !== null && $pageRival !== '' && $pageRival !== $expectedRival) {
            $this->warnAndLog("Skipped {$team->short_name}: the J{$page->weekNumber} page is against {$pageRival}, the fixture against {$expectedRival}");

            return;
        }

        $links = $linker->link($team, $season, $page->players);
        $fetchedAt = now();
        /** @var list<FutbolFantasyPlayer> $unlinked */
        $unlinked = [];

        DB::transaction(function () use ($page, $links, $fixture, $fetchedAt, &$unlinked): void {
            foreach ($page->players as $ffPlayer) {
                $link = $links[$ffPlayer->futbolfantasyId] ?? null;

                if ($link === null) {
                    $unlinked[] = $ffPlayer;

                    continue;
                }

                $rule = $link['rule']->value;
                $this->linkedByRule[$rule] = ($this->linkedByRule[$rule] ?? 0) + 1;

                FixtureLineupProbability::query()->updateOrCreate(
                    ['player_id' => $link['player']->id, 'fixture_id' => $fixture->id],
                    $ffPlayer->confirmedStarter === null
                        ? [
                            'probability' => $ffPlayer->probability,
                            'predicted_starter' => $ffPlayer->predictedStarter,
                            'confirmed_starter' => null,
                            'fetched_at' => $fetchedAt,
                        ]
                        : [
                            'confirmed_starter' => $ffPlayer->confirmedStarter,
                            'fetched_at' => $fetchedAt,
                        ],
                );
            }
        });

        $names = array_map(
            fn (FutbolFantasyPlayer $ffPlayer): string => "{$ffPlayer->name} ({$team->short_name}, FF {$ffPlayer->futbolfantasyId})",
            $unlinked,
        );

        if ($names !== []) {
            $this->unlinked = [...$this->unlinked, ...$names];
            Log::warning('season:sync-start-probabilities — unlinked FútbolFantasy players: '.implode(', ', $names));
        }
    }

    /**
     * @param  array{fetched: int, failed: int, not_due: int, no_match: int}  $counts
     * @param  list<string>  $missingFromMap
     */
    private function summarize(array $counts, array $missingFromMap): void
    {
        $this->info("Teams: {$counts['fetched']} fetched, {$counts['failed']} failed, {$counts['not_due']} not due, {$counts['no_match']} without an upcoming match.");
        $this->info("Players: {$this->playersParsed} parsed.");
        $this->info(sprintf(
            'Linked: %d by stored id, %d by market value, %d by name, %d by manual map.',
            ...array_map(fn (FutbolFantasyLinkRule $rule): int => $this->linkedByRule[$rule->value] ?? 0, FutbolFantasyLinkRule::cases()),
        ));

        if ($this->unlinked !== []) {
            $this->warn('Unlinked ('.count($this->unlinked).'): '.implode(', ', $this->unlinked));
        }

        if ($missingFromMap !== []) {
            $this->warnAndLog('Missing from the FútbolFantasy team map: '.implode(', ', $missingFromMap));
        }
    }

    private function warnAndLog(string $message): void
    {
        $this->warn($message);
        Log::warning("season:sync-start-probabilities — {$message}");
    }
}
```

- [ ] **Step 6: Schedule it**

In `bootstrap/app.php`, inside `withSchedule`, after the `season:sync-current-match-data` block, add:

```php
        $schedule->command('season:sync-start-probabilities')
            ->everyTenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `herd php artisan test --compact tests/Unit/Http/Integrations/FutbolFantasy tests/Feature/Console/Commands/SyncCurrentSeasonStartProbabilitiesTest.php`
Expected: PASS (all of them).

- [ ] **Step 8: Format, analyse, run the whole suite, commit**

Run: `herd php vendor/bin/pint --dirty --format agent`, `herd composer phpstan`, `herd php artisan test --compact`
Expected: no errors; the whole suite passes.

```bash
git add app/Http/Integrations/FutbolFantasy config/services.php app/Console/Commands/SyncCurrentSeasonStartProbabilities.php bootstrap/app.php tests/Unit/Http/Integrations/FutbolFantasy tests/Feature/Console/Commands/SyncCurrentSeasonStartProbabilitiesTest.php
git commit -m "feat: sync start probabilities from FútbolFantasy every 10 minutes" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

- [ ] **Step 9: CHECKPOINT — run it for real and report to the user before any UI task**

Run: `herd php artisan season:sync-start-probabilities --force`. With 20 pages and 10–30 s pauses this takes ~4–10 minutes, so use a long timeout or run it in the background.

Report to the user, verbatim: the `Teams:`, `Players:`, `Linked:`, `Unlinked (…)` and `Missing from the FútbolFantasy team map:` lines. Add the row count, `select count(*) from fixture_lineup_probabilities`, and a couple of sample rows (player nickname, fixture, probability).

**Stop here.** Wait for the user's go-ahead before Task 5. If the user supplies `ffId => fantasy_id` entries for the unlinked players, add them to `FutbolFantasyPlayerLinker::PLAYER_MAP` (one commented line each, like `LinkMatchDataPlayers::PLAYER_MAP`), run `--force` again, and commit that as `fix: map the FútbolFantasy players no rule links`.

---

### Task 5: Open the worldcup26 pre-match window 1 h 30 min before kickoff

**Files:**
- Modify: `app/Console/Commands/SyncLiveSeasonMatchData.php`
- Test: `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`

**Interfaces:**
- Produces: `season:sync-live-match-data` selects fixtures whose kickoff is ≤ 90 min away (was 60). It keeps re-reading the official lineup on every run until kickoff, as it already does, and `syncLineups()` still upserts and prunes, so late corrections replace the stored lineup.

- [ ] **Step 1: Update and add the failing tests**

In `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`:

1. Rename the test `'starts syncing a fixture up to 1 hour before kickoff, to pick up lineups early'` to `'starts syncing a fixture up to 1 h 30 min before kickoff, to pick up lineups early'`, and change its fixture `'date' => now()->addMinutes(30),` to `'date' => now()->addMinutes(85),`.

2. Add these two tests right after it:

```php
test('picks up the official lineup published 1 h 30 min before kickoff', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['wc26_id' => 83]);
    $away = Team::factory()->create(['wc26_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'wc26_id' => 401882926,
        'date' => now()->addMinutes(85),
    ]);
    $player = Player::factory()->create(['team_id' => $home->id, 'wc26_id' => 5001]);

    $payload = liveMatchEventPayload([
        'header' => ['competitions' => [['status' => ['type' => ['name' => 'STATUS_SCHEDULED']]]]],
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Starter One'], 'starter' => true, 'position' => ['displayName' => 'Goalkeeper'], 'jersey' => '1', 'stats' => []],
                ],
            ],
        ],
    ]);

    app()->instance(Worldcup26Connector::class, (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ])));
    app()->instance(LaLigaFantasyConnector::class, (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make(['playerStats' => []]),
    ])));

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->where('fixture_id', $fixture->id)->sole();
    expect($lineup->player_id)->toBe($player->id)
        ->and($lineup->starter)->toBeTrue();
});

test('does not sync a fixture more than 1 h 30 min before kickoff', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['wc26_id' => 83]);
    $away = Team::factory()->create(['wc26_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'wc26_id' => 401882926,
        'date' => now()->addMinutes(95),
    ]);

    app()->instance(Worldcup26Connector::class, (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make(liveMatchEventPayload()),
    ])));

    $this->artisan(SyncLiveSeasonMatchData::class)
        ->expectsOutput('0 fixtures synced.')
        ->assertSuccessful();
});
```

(`LaLigaFantasyConnector`, `GetPlayerRequest`, `FixtureLineup`, `Player` are already imported at the top of that file.)

- [ ] **Step 2: Run the tests to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php --filter="1 h 30 min"`
Expected: FAIL. The 85-minute fixtures are outside the current 1-hour window (`0 fixtures synced.` / no lineup row).

- [ ] **Step 3: Widen the window**

In `app/Console/Commands/SyncLiveSeasonMatchData.php`, replace the `PRE_MATCH_WINDOW_HOURS` constant and its docblock with:

```php
    /**
     * Worldcup26 can publish official lineups before kickoff. Starting the
     * sync 1 h 30 min early — and re-reading on every run until kickoff, so
     * a late correction replaces the stored lineup — lets the fichas switch
     * from FútbolFantasy's probable XI to the confirmed one as soon as it's out.
     */
    private const int PRE_MATCH_WINDOW_MINUTES = 90;
```

and in `handle()` replace `->where('date', '<=', now()->addHours(self::PRE_MATCH_WINDOW_HOURS))` with:

```php
            ->where('date', '<=', now()->addMinutes(self::PRE_MATCH_WINDOW_MINUTES))
```

- [ ] **Step 4: Run the file's tests to verify they pass**

Run: `herd php artisan test --compact tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`
Expected: PASS. The existing `'ignores fixtures outside the live window'` test (a fixture 2 h away) still passes.

- [ ] **Step 5: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`

```bash
git add app/Console/Commands/SyncLiveSeasonMatchData.php tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php
git commit -m "feat: read worldcup26 lineups from 1 h 30 min before kickoff" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Start probability props for the match, team and manager pages

**Files:**
- Create: `app/Services/StartProbabilities.php`
- Modify: `app/Http/Controllers/FixturesController.php` (`show()`), `app/Http/Controllers/TeamsController.php` (`show()`), `app/Http/Controllers/SeasonManagersController.php` (`show()`)
- Modify: `resources/js/types/models.ts`
- Test: `tests/Feature/Services/StartProbabilitiesTest.php`; add tests to `tests/Feature/Http/Controllers/FixturesControllerTest.php`, `TeamsControllerTest.php`, `SeasonManagersControllerTest.php`

**Interfaces:**
- Consumes: `FixtureLineupProbability` (Task 1), `FutbolFantasyTeams::pageUrlFor` (Task 1), `FixtureLineup` (worldcup26), `AttachesCurrentPlayerSeason`.
- Produces (PHP shapes, mirrored in TS below):
  - `StartProbabilities::forFixture(Fixture $fixture): ?array{local: StartTeamBlock|null, guest: StartTeamBlock|null}`. It returns null unless the fixture is `Scheduled` and at least one side has data.
  - `StartProbabilities::forTeamNextFixture(Team $team, Season $season): ?array` returns `StartTeamBlock` plus `{opponent: Team, is_home: bool}` for the team's next `Scheduled` fixture by date (the "próximo partido"), or null.
  - `StartProbabilities::forPlayersNextFixture(Collection<int, Player> $players, Season $season): array<int, PlayerNextStart>`, keyed by player id. Players without data and out-of-league players are absent.
  - `StartTeamBlock = {fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null (ISO 8601), is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, players: list<{player: Player (with team + current-season position), probability: int|null, predicted_starter: bool, confirmed_starter: bool|null}>}`
  - `PlayerNextStart = {fixture_id, week_number, probability, predicted_starter, confirmed_starter, confirmed_source, is_stale, fetched_at, source_url, team_short_name}`
  - Inertia props: `fixtures/show` → `startProbabilities: FixtureStartProbabilities | null`; `teams/show` → `startProbabilities: TeamNextStartProbabilities | null`; `season-managers/show` → `roster[].player.next_start: PlayerNextStart | null`.

**Rules.** `confirmed_source` is `worldcup26` when the fixture has any resolved `fixture_lineups` rows for that team. It is `futbolfantasy` when any FF row of the team has `confirmed_starter` set. Otherwise it is null.
- With worldcup26: `confirmed_starter` = that player's lineup `starter`, or `false` when he isn't in the lineup. Lineup players with no FF row are appended with `probability: null, predicted_starter: false`.
- `is_stale` = the newest `fetched_at` is older than 48 h, and it is never stale once confirmed by worldcup26.

- [ ] **Step 1: Write the failing service test**

`tests/Feature/Services/StartProbabilitiesTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\FixtureLineupProbability;
use App\Models\Season;
use App\Models\Team;
use App\Services\StartProbabilities;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(8)]);
    $this->madrid = Team::factory()->create(['fantasy_id' => 15, 'short_name' => 'RMA']);
    $this->villarreal = Team::factory()->create(['fantasy_id' => 20, 'short_name' => 'VIL']);
    $this->fixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'week_number' => 8,
        'team_local_id' => $this->madrid->id,
        'team_guest_id' => $this->villarreal->id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
});

function startPlayer(Team $team, string $nickname, PlayerPosition $position = PlayerPosition::Midfield): Player
{
    return Player::factory()->create([
        'team_id' => $team->id,
        'nickname' => $nickname,
        'status' => PlayerStatus::Ok,
        'position' => $position,
    ]);
}

function startRow(Player $player, Fixture $fixture, array $attributes = []): FixtureLineupProbability
{
    return FixtureLineupProbability::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'probability' => 70,
        'predicted_starter' => true,
        'confirmed_starter' => null,
        'fetched_at' => now(),
        ...$attributes,
    ]);
}

test('gives an upcoming fixture one block per side with each player\'s probability', function (): void {
    $courtois = startPlayer($this->madrid, 'Courtois', PlayerPosition::Goalkeeper);
    startRow($courtois, $this->fixture, ['probability' => 95]);
    startRow(startPlayer($this->villarreal, 'Parejo'), $this->fixture, ['probability' => 80]);

    $result = app(StartProbabilities::class)->forFixture($this->fixture);

    expect($result['local']['team']->id)->toBe($this->madrid->id)
        ->and($result['local']['week_number'])->toBe(8)
        ->and($result['local']['source_url'])->toBe('https://www.futbolfantasy.com/laliga/equipos/real-madrid')
        ->and($result['local']['confirmed_source'])->toBeNull()
        ->and($result['local']['is_stale'])->toBeFalse()
        ->and($result['local']['fetched_at'])->not->toBeNull()
        ->and($result['local']['players'])->toHaveCount(1)
        ->and($result['local']['players'][0]['player']->id)->toBe($courtois->id)
        ->and($result['local']['players'][0]['player']->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($result['local']['players'][0]['probability'])->toBe(95)
        ->and($result['local']['players'][0]['confirmed_starter'])->toBeNull()
        ->and($result['guest']['players'][0]['probability'])->toBe(80);
});

test('flags data older than 48 hours as stale', function (): void {
    startRow(startPlayer($this->madrid, 'Courtois'), $this->fixture, ['fetched_at' => now()->subHours(49)]);

    expect(app(StartProbabilities::class)->forFixture($this->fixture)['local']['is_stale'])->toBeTrue();
});

test('keeps a side without data empty instead of failing', function (): void {
    startRow(startPlayer($this->madrid, 'Courtois'), $this->fixture);

    $result = app(StartProbabilities::class)->forFixture($this->fixture);

    expect($result['local'])->not->toBeNull()
        ->and($result['guest'])->toBeNull();
});

test('gives nothing for a fixture without data or already kicked off', function (): void {
    expect(app(StartProbabilities::class)->forFixture($this->fixture))->toBeNull();

    startRow(startPlayer($this->madrid, 'Courtois'), $this->fixture);
    $this->fixture->update(['state' => FixtureState::FirstHalf]);

    expect(app(StartProbabilities::class)->forFixture($this->fixture->refresh()))->toBeNull()
        ->and(FixtureLineupProbability::query()->count())->toBe(1);
});

test('uses FútbolFantasy\'s confirmed lineup while worldcup26 has none', function (): void {
    $endrick = startPlayer($this->madrid, 'Endrick');
    startRow($endrick, $this->fixture, ['probability' => 10, 'predicted_starter' => false, 'confirmed_starter' => true]);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];

    expect($local['confirmed_source'])->toBe('futbolfantasy')
        ->and($local['players'][0]['confirmed_starter'])->toBeTrue()
        ->and($local['players'][0]['probability'])->toBe(10);
});

test('lets the worldcup26 lineup win over FútbolFantasy and adds its players FF never listed', function (): void {
    $vinicius = startPlayer($this->madrid, 'Vini Jr.');
    $endrick = startPlayer($this->madrid, 'Endrick');
    $gonzalo = startPlayer($this->madrid, 'Gonzalo');
    startRow($vinicius, $this->fixture, ['probability' => 60, 'confirmed_starter' => true]);
    startRow($endrick, $this->fixture, ['probability' => 10, 'predicted_starter' => false, 'confirmed_starter' => false]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $endrick->id, 'starter' => true]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $gonzalo->id, 'starter' => true]);

    $local = app(StartProbabilities::class)->forFixture($this->fixture)['local'];
    $byPlayer = collect($local['players'])->keyBy(fn (array $entry): int => $entry['player']->id);

    expect($local['confirmed_source'])->toBe('worldcup26')
        ->and($local['is_stale'])->toBeFalse()
        ->and($byPlayer[$vinicius->id]['confirmed_starter'])->toBeFalse()
        ->and($byPlayer[$vinicius->id]['probability'])->toBe(60)
        ->and($byPlayer[$endrick->id]['confirmed_starter'])->toBeTrue()
        ->and($byPlayer[$gonzalo->id]['confirmed_starter'])->toBeTrue()
        ->and($byPlayer[$gonzalo->id]['probability'])->toBeNull()
        ->and($byPlayer[$gonzalo->id]['predicted_starter'])->toBeFalse();
});

test('gives a team the block of its next match with the opponent', function (): void {
    startRow(startPlayer($this->villarreal, 'Parejo'), $this->fixture, ['probability' => 80]);

    $block = app(StartProbabilities::class)->forTeamNextFixture($this->villarreal, $this->season);

    expect($block['fixture_id'])->toBe($this->fixture->id)
        ->and($block['opponent']->id)->toBe($this->madrid->id)
        ->and($block['is_home'])->toBeFalse()
        ->and($block['players'][0]['probability'])->toBe(80)
        ->and(app(StartProbabilities::class)->forTeamNextFixture($this->madrid, $this->season))->toBeNull();
});

test('gives each player the start of his team\'s next match', function (): void {
    $courtois = startPlayer($this->madrid, 'Courtois');
    $unlisted = startPlayer($this->madrid, 'Sin dato');
    $gone = Player::factory()->create(['team_id' => $this->madrid->id, 'status' => PlayerStatus::OutOfLeague]);
    startRow($courtois, $this->fixture, ['probability' => 95, 'fetched_at' => now()->subDays(3)]);
    startRow($gone, $this->fixture);

    $players = Player::query()->whereIn('id', [$courtois->id, $unlisted->id, $gone->id])->with('team')->get();
    $nextStarts = app(StartProbabilities::class)->forPlayersNextFixture($players, $this->season);

    expect($nextStarts)->toHaveKey($courtois->id)
        ->and($nextStarts)->not->toHaveKey($unlisted->id)
        ->and($nextStarts)->not->toHaveKey($gone->id)
        ->and($nextStarts[$courtois->id]['week_number'])->toBe(8)
        ->and($nextStarts[$courtois->id]['probability'])->toBe(95)
        ->and($nextStarts[$courtois->id]['is_stale'])->toBeTrue()
        ->and($nextStarts[$courtois->id]['confirmed_source'])->toBeNull()
        ->and($nextStarts[$courtois->id]['team_short_name'])->toBe('RMA')
        ->and($nextStarts[$courtois->id]['source_url'])->toBe('https://www.futbolfantasy.com/laliga/equipos/real-madrid');
});

test('a player\'s next start follows the worldcup26 lineup once there is one', function (): void {
    $courtois = startPlayer($this->madrid, 'Courtois');
    $lunin = startPlayer($this->madrid, 'Lunin');
    startRow($courtois, $this->fixture, ['probability' => 95]);
    FixtureLineup::factory()->create(['fixture_id' => $this->fixture->id, 'team_id' => $this->madrid->id, 'player_id' => $lunin->id, 'starter' => true]);

    $players = Player::query()->whereIn('id', [$courtois->id, $lunin->id])->with('team')->get();
    $nextStarts = app(StartProbabilities::class)->forPlayersNextFixture($players, $this->season);

    expect($nextStarts[$courtois->id]['confirmed_source'])->toBe('worldcup26')
        ->and($nextStarts[$courtois->id]['confirmed_starter'])->toBeFalse()
        ->and($nextStarts[$courtois->id]['probability'])->toBe(95)
        ->and($nextStarts[$lunin->id]['confirmed_starter'])->toBeTrue()
        ->and($nextStarts[$lunin->id]['probability'])->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Services/StartProbabilitiesTest.php`
Expected: FAIL with `Class "App\Services\StartProbabilities" not found`.

- [ ] **Step 3: Write the service**

`app/Services/StartProbabilities.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\FixtureLineupProbability;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the match, team and manager fichas show about who starts:
 * FútbolFantasy's start probabilities for a fixture or — once there is one —
 * the confirmed lineup. Confirmed lineups come from worldcup26
 * (`fixture_lineups`) first and from FF's "Alineación confirmada"
 * (`confirmed_starter`) only while worldcup26 has none; when both exist,
 * worldcup26 wins.
 *
 * Rows are never deleted after kickoff — they keep the last predicted %
 * behind the "Sorpresa / Se cae · era N %" marks. The match ficha just
 * stops asking once its fixture has kicked off.
 *
 * @phpstan-type StartEntry array{player: Player, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null}
 * @phpstan-type StartTeamBlock array{fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null, is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, players: list<StartEntry>}
 * @phpstan-type PlayerNextStart array{fixture_id: int, week_number: int, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, confirmed_source: 'worldcup26'|'futbolfantasy'|null, is_stale: bool, fetched_at: string|null, source_url: string, team_short_name: string}
 */
class StartProbabilities
{
    use AttachesCurrentPlayerSeason;

    /** Data older than this is shown as stale ("Datos de hace N días"). */
    public const int STALE_AFTER_HOURS = 48;

    /**
     * Both sides of a fixture that hasn't kicked off — null once it has, or
     * when neither side has any data. A side without data is null.
     *
     * @return array{local: StartTeamBlock|null, guest: StartTeamBlock|null}|null
     */
    public function forFixture(Fixture $fixture): ?array
    {
        if ($fixture->state !== FixtureState::Scheduled) {
            return null;
        }

        $fixture->loadMissing(['localTeam', 'guestTeam']);

        $local = $this->teamBlock($fixture, $fixture->localTeam);
        $guest = $this->teamBlock($fixture, $fixture->guestTeam);

        return $local === null && $guest === null ? null : ['local' => $local, 'guest' => $guest];
    }

    /**
     * The team's block for its next match — the "próximo partido" the fichas
     * show — or null when that match has no data yet.
     *
     * @return array{fixture_id: int, week_number: int, team: Team, source_url: string, fetched_at: string|null, is_stale: bool, confirmed_source: 'worldcup26'|'futbolfantasy'|null, players: list<StartEntry>, opponent: Team, is_home: bool}|null
     */
    public function forTeamNextFixture(Team $team, Season $season): ?array
    {
        $fixture = $this->nextFixtures($season, [$team->id])[$team->id] ?? null;

        if ($fixture === null) {
            return null;
        }

        $block = $this->teamBlock($fixture, $team);

        if ($block === null) {
            return null;
        }

        $isHome = $fixture->team_local_id === $team->id;

        return [
            ...$block,
            'opponent' => $isHome ? $fixture->guestTeam : $fixture->localTeam,
            'is_home' => $isHome,
        ];
    }

    /**
     * Each player's start for his team's next match, keyed by player id.
     * Out-of-league players, and players without a row or a confirmed
     * lineup for that match, are absent. Expects `team` to be loaded.
     *
     * @param  Collection<int, Player>  $players
     * @return array<int, PlayerNextStart>
     */
    public function forPlayersNextFixture(Collection $players, Season $season): array
    {
        $eligible = $players->filter(fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague);
        $teamIds = array_values(array_unique($eligible->map(fn (Player $player): int => $player->team_id)->all()));
        $nextByTeam = $this->nextFixtures($season, $teamIds);

        if ($nextByTeam === []) {
            return [];
        }

        $fixtureIds = array_values(array_map(fn (Fixture $fixture): int => $fixture->id, $nextByTeam));

        $rows = FixtureLineupProbability::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereIn('player_id', $eligible->map(fn (Player $player): int => $player->id)->all())
            ->get()
            ->keyBy(fn (FixtureLineupProbability $row): string => "{$row->fixture_id}:{$row->player_id}");

        $lineups = FixtureLineup::query()
            ->whereIn('fixture_id', $fixtureIds)
            ->whereNotNull('player_id')
            ->get(['fixture_id', 'team_id', 'player_id', 'starter']);

        $confirmedTeams = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => ["{$lineup->fixture_id}:{$lineup->team_id}" => true]);
        $lineupStarters = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => ["{$lineup->fixture_id}:{$lineup->player_id}" => $lineup->starter]);

        $nextStarts = [];

        foreach ($eligible as $player) {
            $fixture = $nextByTeam[$player->team_id] ?? null;

            if ($fixture === null) {
                continue;
            }

            $row = $rows->get("{$fixture->id}:{$player->id}");
            $byWorldcup26 = $confirmedTeams->has("{$fixture->id}:{$player->team_id}");

            if ($row === null && !$byWorldcup26) {
                continue;
            }

            $confirmedSource = match (true) {
                $byWorldcup26 => 'worldcup26',
                $row?->confirmed_starter !== null => 'futbolfantasy',
                default => null,
            };

            $nextStarts[$player->id] = [
                'fixture_id' => $fixture->id,
                'week_number' => $fixture->week_number,
                'probability' => $row?->probability,
                'predicted_starter' => $row !== null && $row->predicted_starter,
                'confirmed_starter' => match ($confirmedSource) {
                    'worldcup26' => (bool) $lineupStarters->get("{$fixture->id}:{$player->id}", false),
                    'futbolfantasy' => $row?->confirmed_starter,
                    default => null,
                },
                'confirmed_source' => $confirmedSource,
                'is_stale' => $confirmedSource !== 'worldcup26' && $row !== null && $this->isStale($row->fetched_at),
                'fetched_at' => $row?->fetched_at->toIso8601String(),
                'source_url' => FutbolFantasyTeams::pageUrlFor($player->team->fantasy_id) ?? '',
                'team_short_name' => $player->team->short_name,
            ];
        }

        return $nextStarts;
    }

    /**
     * @return StartTeamBlock|null
     */
    private function teamBlock(Fixture $fixture, Team $team): ?array
    {
        $rows = FixtureLineupProbability::query()
            ->where('fixture_id', $fixture->id)
            ->whereHas('player', fn ($query) => $query->where('team_id', $team->id))
            ->with('player.team')
            ->get();

        $lineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->where('team_id', $team->id)
            ->whereNotNull('player_id')
            ->with('player.team')
            ->get();

        if ($rows->isEmpty() && $lineups->isEmpty()) {
            return null;
        }

        $confirmedSource = match (true) {
            $lineups->isNotEmpty() => 'worldcup26',
            $rows->contains(fn (FixtureLineupProbability $row): bool => $row->confirmed_starter !== null) => 'futbolfantasy',
            default => null,
        };

        $startersByPlayer = $lineups->mapWithKeys(fn (FixtureLineup $lineup): array => [(int) $lineup->player_id => $lineup->starter]);

        /** @var list<StartEntry> $players */
        $players = [];

        foreach ($rows as $row) {
            $players[] = [
                'player' => $row->player,
                'probability' => $row->probability,
                'predicted_starter' => $row->predicted_starter,
                'confirmed_starter' => match ($confirmedSource) {
                    'worldcup26' => (bool) $startersByPlayer->get($row->player_id, false),
                    'futbolfantasy' => $row->confirmed_starter ?? false,
                    default => null,
                },
            ];
        }

        $listed = $rows->pluck('player_id')->flip();

        foreach ($lineups as $lineup) {
            if (!$lineup->player instanceof Player || $listed->has($lineup->player_id)) {
                continue;
            }

            $players[] = [
                'player' => $lineup->player,
                'probability' => null,
                'predicted_starter' => false,
                'confirmed_starter' => $lineup->starter,
            ];
        }

        $this->attachCurrentSeason(collect(array_map(fn (array $entry): Player => $entry['player'], $players)), $fixture->season_id);

        $fetchedAt = $rows->sortByDesc(fn (FixtureLineupProbability $row): int => $row->fetched_at->getTimestamp())->first()?->fetched_at;

        return [
            'fixture_id' => $fixture->id,
            'week_number' => $fixture->week_number,
            'team' => $team,
            'source_url' => FutbolFantasyTeams::pageUrlFor($team->fantasy_id) ?? '',
            'fetched_at' => $fetchedAt?->toIso8601String(),
            'is_stale' => $confirmedSource !== 'worldcup26' && $fetchedAt !== null && $this->isStale($fetchedAt),
            'confirmed_source' => $confirmedSource,
            'players' => $players,
        ];
    }

    /**
     * Each team's next match: the soonest `Scheduled` fixture by date — the
     * same "próximo partido" the team ficha and the roster show.
     *
     * @param  list<int>  $teamIds
     * @return array<int, Fixture>
     */
    private function nextFixtures(Season $season, array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $nextByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds))
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get()
            ->each(function (Fixture $fixture) use ($teamIds, &$nextByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true) && !isset($nextByTeam[$teamId])) {
                        $nextByTeam[$teamId] = $fixture;
                    }
                }
            });

        return $nextByTeam;
    }

    private function isStale(CarbonImmutable $fetchedAt): bool
    {
        return $fetchedAt->lessThan(now()->subHours(self::STALE_AFTER_HOURS));
    }
}
```

- [ ] **Step 4: Run the service test to verify it passes**

Run: `herd php artisan test --compact tests/Feature/Services/StartProbabilitiesTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Write the failing controller tests**

Append to `tests/Feature/Http/Controllers/FixturesControllerTest.php`. Add `use App\Enums\PlayerStatus;`, `use App\Models\FixtureLineupProbability;` and `use App\Models\Team;` to its imports if they aren't there yet.

```php
test('sends the start probabilities of an upcoming fixture', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $madrid = Team::factory()->create(['fantasy_id' => 15]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'team_local_id' => $madrid->id, 'date' => now()->addDay()]);
    $courtois = Player::factory()->create(['team_id' => $madrid->id, 'status' => PlayerStatus::Ok]);
    FixtureLineupProbability::factory()->create(['player_id' => $courtois->id, 'fixture_id' => $fixture->id, 'probability' => 95]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('startProbabilities.local.players.0.player.id', $courtois->id)
        ->where('startProbabilities.local.players.0.probability', 95)
        ->where('startProbabilities.local.source_url', 'https://www.futbolfantasy.com/laliga/equipos/real-madrid')
        ->where('startProbabilities.guest', null)
    );
});

test('sends no start probabilities for a fixture without any', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    $this->get(route('fixtures.show', $fixture))
        ->assertInertia(fn (Assert $page): AssertableInertia => $page->where('startProbabilities', null));
});
```

Append to `tests/Feature/Http/Controllers/TeamsControllerTest.php`, adding `use App\Models\FixtureLineupProbability;` to its imports:

```php
test('sends the probable XI of the team\'s next match', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $team = Team::factory()->create(['fantasy_id' => 20]);
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 8,
        'team_local_id' => $rival->id,
        'team_guest_id' => $team->id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    FixtureLineupProbability::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'probability' => 80]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('startProbabilities.week_number', 8)
        ->where('startProbabilities.is_home', false)
        ->where('startProbabilities.opponent.id', $rival->id)
        ->where('startProbabilities.players.0.probability', 80)
    );
});
```

Append to `tests/Feature/Http/Controllers/SeasonManagersControllerTest.php`, adding `use App\Models\FixtureLineupProbability;` to its imports:

```php
test('sends each roster player\'s start for his team\'s next match', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create(['fantasy_id' => 15, 'short_name' => 'RMA']);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 8,
        'team_local_id' => $team->id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
    $listed = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $unlisted = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    FixtureLineupProbability::factory()->create(['player_id' => $listed->id, 'fixture_id' => $fixture->id, 'probability' => 70, 'predicted_starter' => true]);
    ManagerPlayer::factory()->create(['season_manager_id' => $seasonManager->id, 'player_id' => $listed->id]);
    ManagerPlayer::factory()->create(['season_manager_id' => $seasonManager->id, 'player_id' => $unlisted->id]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) use ($listed): AssertableInertia {
        $roster = collect($page->toArray()['props']['roster'])->keyBy('player.id');

        expect($roster[$listed->id]['player']['next_start']['probability'])->toBe(70)
            ->and($roster[$listed->id]['player']['next_start']['week_number'])->toBe(8)
            ->and($roster[$listed->id]['player']['next_start']['team_short_name'])->toBe('RMA')
            ->and($roster->firstWhere('player.id', '!=', $listed->id)['player']['next_start'])->toBeNull();

        return $page;
    });
});
```

- [ ] **Step 6: Run the controller tests to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/FixturesControllerTest.php tests/Feature/Http/Controllers/TeamsControllerTest.php tests/Feature/Http/Controllers/SeasonManagersControllerTest.php --filter="start|probable XI"`
Expected: FAIL. The props `startProbabilities` and `next_start` are missing.

- [ ] **Step 7: Wire the controllers**

`app/Http/Controllers/FixturesController.php`: add `use App\Services\StartProbabilities;`. Change the signature to:

```php
    public function show(Fixture $fixture, FixtureFantasyScoreboard $fantasyScoreboard, StartProbabilities $startProbabilities): Response
```

and add as the last entry of the `Inertia::render('fixtures/show', [...])` array:

```php
            'startProbabilities' => $startProbabilities->forFixture($fixture),
```

`app/Http/Controllers/TeamsController.php`: add `use App\Services\StartProbabilities;`, change `public function show(Team $team): Response` to:

```php
    public function show(Team $team, StartProbabilities $startProbabilities): Response
```

and add as the last entry of the `Inertia::render('teams/show', [...])` array:

```php
            'startProbabilities' => $startProbabilities->forTeamNextFixture($team, $season),
```

`app/Http/Controllers/SeasonManagersController.php`: add `use App\Services\StartProbabilities;`. Change `public function show(SeasonManager $seasonManager): Response` to:

```php
    public function show(SeasonManager $seasonManager, StartProbabilities $startProbabilities): Response
```

and right after `$this->attachNextFixtures($roster->pluck('player'), $season);` add:

```php
        $nextStarts = $startProbabilities->forPlayersNextFixture($roster->pluck('player'), $season);

        $roster->each(function (ManagerPlayer $entry) use ($nextStarts): void {
            $entry->player->next_start = $nextStarts[$entry->player->id] ?? null;
        });
```

- [ ] **Step 8: Add the TypeScript types**

In `resources/js/types/models.ts`, add to the `Player` interface after `next_fixtures`:

```ts
    /** Start probability (or confirmed lineup) for the team's next match. Only present on the manager ficha; null without data. */
    next_start?: PlayerNextStart | null;
```

and append at the end of the file:

```ts
/** Who confirmed a lineup: worldcup26 (primary) or FútbolFantasy's "Alineación confirmada" (fallback). */
export type StartConfirmationSource = 'worldcup26' | 'futbolfantasy';

/** One player's start for one fixture. */
export interface StartProbabilityEntry {
    player: Player;
    /** FútbolFantasy's last predicted % (0–100) — kept after confirmation for "era N %"; null when FF gave none. */
    probability: number | null;
    /** In FútbolFantasy's probable XI. */
    predicted_starter: boolean;
    /** Confirmed lineup (worldcup26 first, FF second): true titular, false suplente, null not confirmed yet. */
    confirmed_starter: boolean | null;
}

/** One team's side of a fixture's start probabilities. */
export interface StartProbabilityTeamBlock {
    fixture_id: number;
    week_number: number;
    team: Team;
    /** The team's FútbolFantasy page, for the attribution link. */
    source_url: string;
    /** When FútbolFantasy was last read successfully (ISO 8601). */
    fetched_at: string | null;
    /** Older than 48 h: shown as "Datos de hace N días" with muted bars. */
    is_stale: boolean;
    confirmed_source: StartConfirmationSource | null;
    players: StartProbabilityEntry[];
}

/** The match ficha's start probabilities — a side without data is null. */
export interface FixtureStartProbabilities {
    local: StartProbabilityTeamBlock | null;
    guest: StartProbabilityTeamBlock | null;
}

/** The team ficha's probable XI for its next match. */
export interface TeamNextStartProbabilities extends StartProbabilityTeamBlock {
    opponent: Team;
    is_home: boolean;
}

/** A roster player's start for his team's next match (manager ficha). */
export interface PlayerNextStart {
    fixture_id: number;
    week_number: number;
    probability: number | null;
    predicted_starter: boolean;
    confirmed_starter: boolean | null;
    confirmed_source: StartConfirmationSource | null;
    is_stale: boolean;
    fetched_at: string | null;
    source_url: string;
    team_short_name: string;
}
```

- [ ] **Step 9: Run the tests, format, analyse, type-check**

Run: `herd php artisan test --compact tests/Feature/Services/StartProbabilitiesTest.php tests/Feature/Http/Controllers`
Expected: PASS.

Run: `herd php vendor/bin/pint --dirty --format agent`, `herd composer phpstan`, `npm run types:check`
Expected: no errors.

- [ ] **Step 10: Commit**

```bash
git add app/Services/StartProbabilities.php app/Http/Controllers/FixturesController.php app/Http/Controllers/TeamsController.php app/Http/Controllers/SeasonManagersController.php resources/js/types/models.ts tests/Feature/Services/StartProbabilitiesTest.php tests/Feature/Http/Controllers/FixturesControllerTest.php tests/Feature/Http/Controllers/TeamsControllerTest.php tests/Feature/Http/Controllers/SeasonManagersControllerTest.php
git commit -m "feat: send start probabilities to the match, team and manager fichas" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: Match page, the probable/confirmed XI section (variant A)

**Files:**
- Create: `resources/js/lib/start-probability.ts`
- Create: `resources/js/components/hq-start-probability.tsx`
- Modify: `resources/js/components/hq-match-pitch.tsx` (export the pitch markings)
- Create: `resources/js/components/hq-probable-match-pitch.tsx`
- Create: `resources/js/components/hq-start-probabilities-section.tsx`
- Modify: `resources/js/pages/fixtures/show.tsx`

**Interfaces:**
- Consumes: `FixtureStartProbabilities`, `StartProbabilityTeamBlock`, `StartProbabilityEntry` (Task 6 types); the `fixtures/show` prop `startProbabilities` (Task 6).
- Produces (shared with Tasks 8–9):
  - `@/lib/start-probability`: `DOUBT_THRESHOLD = 60`, `BENCH_THRESHOLD = 30`, `SURE_STARTER_THRESHOLD = 90`, `type StartTone`, `START_TONE_TEXT_CLASSES`, `START_TONE_BG_CLASSES`, `START_TONE_BORDER_CLASSES`, `isUnavailable(status)`, `startTone(probability, status)`, `pitchBadgeTone(probability, status)`, `type StartFacts`, `type StartOutcome`, `startOutcome(facts)`, `isShownStarter(facts, confirmed)`, `splitStartEntries(players, confirmed): {starters, bench, rest, out}`, `averageProbability(entries)`, `formatDataAge(fetchedAt, now)`, `dataAgeDays(fetchedAt, now)`, `type StartPitchSlot`, `landscapeSlots(starters, side)`, `halfPitchSlots(starters)`.
  - `@/components/hq-start-probability`: `HqStartMeter({probability, status, size?: 'md'|'sm', muted?, className?})` is **the one 10-cell bar**. Also `HqStartOutcomeChip({facts, className?})`, `HqStartStateLabel({confirmed, stale})`, `HqStartStaleBanner({fetchedAt, now})`, `HqStartAttribution({sources: StartSource[], confirmedByWorldcup26?, className?})`, `HqStartLegend({pitchNoteClassName?, children?})`, `HqStartPitchToken({entry, confirmed, size?: 'lg'|'sm', muted?})`.
  - `@/components/hq-match-pitch`: `MatchPitchLines()`.

- [ ] **Step 1: Write the shared logic**

`resources/js/lib/start-probability.ts`:

```ts
import type {
    PlayerPosition,
    PlayerStatus,
    StartProbabilityEntry,
} from '@/types/models';

/** A probable starter below this is dimmed with a dashed frame on the pitch. */
export const DOUBT_THRESHOLD = 60;

/** A non-starter at or above this is listed under bench/doubts; the rest fold into "+N < 30 %". */
export const BENCH_THRESHOLD = 30;

/** A pitch badge at or above this turns lilac. */
export const SURE_STARTER_THRESHOLD = 90;

/**
 * sure ≥ 90 % (pitch badges only) · high ≥ 70 % · mid 40–69 % · low < 40 %
 * · out = injured/suspended per LaLiga Fantasy · none = FútbolFantasy gave no %.
 */
export type StartTone = 'sure' | 'high' | 'mid' | 'low' | 'out' | 'none';

export const START_TONE_TEXT_CLASSES: Record<StartTone, string> = {
    sure: 'text-hq-violet',
    high: 'text-hq-lime',
    mid: 'text-hq-gold',
    low: 'text-hq-moss-dim',
    out: 'text-hq-live',
    none: 'text-hq-led-off',
};

export const START_TONE_BG_CLASSES: Record<StartTone, string> = {
    sure: 'bg-hq-violet',
    high: 'bg-hq-lime',
    mid: 'bg-hq-gold',
    low: 'bg-hq-moss-dim',
    out: 'bg-hq-live',
    none: 'bg-hq-led-off',
};

export const START_TONE_BORDER_CLASSES: Record<StartTone, string> = {
    sure: 'border-hq-violet',
    high: 'border-hq-lime',
    mid: 'border-hq-gold',
    low: 'border-hq-moss-dim',
    out: 'border-hq-live',
    none: 'border-hq-led-off',
};

/** Injured or suspended per LaLiga Fantasy — our status is the truth, never FútbolFantasy's flags. */
export function isUnavailable(status: PlayerStatus): boolean {
    return status === 'injured' || status === 'suspended';
}

export function startTone(
    probability: number | null,
    status: PlayerStatus,
): StartTone {
    if (isUnavailable(status)) {
        return 'out';
    }

    if (probability === null) {
        return 'none';
    }

    if (probability >= 70) {
        return 'high';
    }

    return probability >= 40 ? 'mid' : 'low';
}

/** {@link startTone}, except that a pitch badge at ≥ 90 % turns lilac. */
export function pitchBadgeTone(
    probability: number | null,
    status: PlayerStatus,
): StartTone {
    const tone = startTone(probability, status);

    return tone === 'high' &&
        probability !== null &&
        probability >= SURE_STARTER_THRESHOLD
        ? 'sure'
        : tone;
}

/** What a start chip needs — shared by fixture entries and a roster player's next start. */
export type StartFacts = Pick<
    StartProbabilityEntry,
    'probability' | 'predicted_starter' | 'confirmed_starter'
>;

export type StartOutcome = 'starter' | 'bench' | 'surprise' | 'dropped';

/**
 * Titular / Suplente once confirmed — "surprise" when he starts outside
 * FútbolFantasy's probable XI, "dropped" when he was in it and doesn't.
 * Null while the lineup isn't confirmed.
 */
export function startOutcome(facts: StartFacts): StartOutcome | null {
    if (facts.confirmed_starter === null) {
        return null;
    }

    if (facts.confirmed_starter && !facts.predicted_starter) {
        return 'surprise';
    }

    if (!facts.confirmed_starter && facts.predicted_starter) {
        return 'dropped';
    }

    return facts.confirmed_starter ? 'starter' : 'bench';
}

/** In the XI shown: the confirmed one once there is one, else FútbolFantasy's probable XI. */
export function isShownStarter(facts: StartFacts, confirmed: boolean): boolean {
    return confirmed ? facts.confirmed_starter === true : facts.predicted_starter;
}

const POSITION_ORDER: Record<PlayerPosition, number> = {
    goalkeeper: 0,
    defender: 1,
    midfield: 2,
    striker: 3,
    coach: 4,
};

function byProbability(
    a: StartProbabilityEntry,
    b: StartProbabilityEntry,
): number {
    return (b.probability ?? -1) - (a.probability ?? -1);
}

export interface StartSplit {
    /** The XI shown, by line then %. */
    starters: StartProbabilityEntry[];
    /** Bench and doubts at ≥ 30 % — once confirmed, also the probable starters who were dropped. */
    bench: StartProbabilityEntry[];
    /** Everyone else under 30 %. */
    rest: StartProbabilityEntry[];
    /** Bajas: injured or suspended per our status, whatever % FF still gives them. */
    out: StartProbabilityEntry[];
}

/** One team's players split for display. Coaches are left out. */
export function splitStartEntries(
    players: StartProbabilityEntry[],
    confirmed: boolean,
): StartSplit {
    const squad = players.filter((entry) => entry.player.position !== 'coach');
    const starters = squad
        .filter((entry) => isShownStarter(entry, confirmed))
        .sort(
            (a, b) =>
                POSITION_ORDER[a.player.position] -
                    POSITION_ORDER[b.player.position] || byProbability(a, b),
        );
    const others = squad.filter((entry) => !isShownStarter(entry, confirmed));
    const out = others
        .filter((entry) => isUnavailable(entry.player.status))
        .sort(byProbability);
    const available = others.filter(
        (entry) => !isUnavailable(entry.player.status),
    );
    const bench = available
        .filter(
            (entry) =>
                (confirmed && entry.predicted_starter) ||
                (entry.probability ?? 0) >= BENCH_THRESHOLD,
        )
        .sort(
            (a, b) =>
                Number(b.predicted_starter) - Number(a.predicted_starter) ||
                byProbability(a, b),
        );
    const rest = available
        .filter((entry) => !bench.includes(entry))
        .sort(byProbability);

    return { starters, bench, rest, out };
}

/** Mean % of the players that have one, rounded — null when none has. */
export function averageProbability(
    entries: StartProbabilityEntry[],
): number | null {
    const known = entries.flatMap((entry) =>
        entry.probability === null ? [] : [entry.probability],
    );

    if (known.length === 0) {
        return null;
    }

    return Math.round(
        known.reduce((sum, value) => sum + value, 0) / known.length,
    );
}

/** Whole days since FútbolFantasy was last read. */
export function dataAgeDays(fetchedAt: string, now: number): number {
    return Math.floor((now - Date.parse(fetchedAt)) / 86_400_000);
}

/** "hace 5 min" / "hace 3 h" / "hace 2 días". */
export function formatDataAge(fetchedAt: string, now: number): string {
    const minutes = Math.max(
        1,
        Math.round((now - Date.parse(fetchedAt)) / 60_000),
    );

    if (minutes < 60) {
        return `hace ${minutes} min`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return `hace ${hours} h`;
    }

    const days = Math.round(hours / 24);

    return `hace ${days} ${days === 1 ? 'día' : 'días'}`;
}

type PitchLine = Exclude<PlayerPosition, 'coach'>;

const PITCH_LINES: PitchLine[] = ['goalkeeper', 'defender', 'midfield', 'striker'];

/** A player's spot on a pitch, in % of its width (left) and height (top). */
export interface StartPitchSlot {
    entry: StartProbabilityEntry;
    left: number;
    top: number;
}

/** A line's players with the strongest % in the middle (alternating either side of it). */
function centreOut(entries: StartProbabilityEntry[]): StartProbabilityEntry[] {
    const ordered: StartProbabilityEntry[] = [];

    [...entries].sort(byProbability).forEach((entry, index) => {
        if (index % 2 === 0) {
            ordered.unshift(entry);
        } else {
            ordered.push(entry);
        }
    });

    return ordered;
}

/** Depth (% of the width from the own goal) of each line on the landscape pitch. */
const LANDSCAPE_DEPTH: Record<PitchLine, number> = {
    goalkeeper: 6,
    defender: 18.5,
    midfield: 31.5,
    striker: 43.5,
};

/**
 * A probable XI on HqMatchPitch's landscape pitch: the local side attacks
 * right, the guest side is mirrored. Lines come from the fantasy position;
 * with four or more defenders the full-backs step up a little.
 */
export function landscapeSlots(
    starters: StartProbabilityEntry[],
    side: 'local' | 'guest',
): StartPitchSlot[] {
    return PITCH_LINES.flatMap((position) => {
        const line = centreOut(
            starters.filter((entry) => entry.player.position === position),
        );

        return line.map((entry, index) => {
            const wide =
                position === 'defender' &&
                line.length >= 4 &&
                (index === 0 || index === line.length - 1);
            const depth = LANDSCAPE_DEPTH[position] + (wide ? 3.5 : 0);
            const across = 9 + ((index + 1) / (line.length + 1)) * 82;

            return side === 'local'
                ? { entry, left: depth, top: across }
                : { entry, left: 100 - depth, top: 100 - across };
        });
    });
}

/** Height (% from the top) of each line on the vertical half pitch, attacking up. */
const HALF_PITCH_TOP: Record<PitchLine, number> = {
    goalkeeper: 88,
    defender: 67,
    midfield: 42,
    striker: 16,
};

/** A probable XI on the team ficha's vertical half pitch, attacking up. */
export function halfPitchSlots(
    starters: StartProbabilityEntry[],
): StartPitchSlot[] {
    return PITCH_LINES.flatMap((position) => {
        const line = centreOut(
            starters.filter((entry) => entry.player.position === position),
        );

        return line.map((entry, index) => {
            const wide =
                position === 'defender' &&
                line.length >= 4 &&
                (index === 0 || index === line.length - 1);

            return {
                entry,
                left: ((index + 1) / (line.length + 1)) * 100,
                top: HALF_PITCH_TOP[position] - (wide ? 4 : 0),
            };
        });
    });
}
```

- [ ] **Step 2: Write the shared components**

`resources/js/components/hq-start-probability.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { User } from 'lucide-react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqStatusBadge } from '@/components/hq-status-badge';
import { formatMatchDateShort } from '@/lib/format';
import {
    DOUBT_THRESHOLD,
    START_TONE_BG_CLASSES,
    START_TONE_BORDER_CLASSES,
    START_TONE_TEXT_CLASSES,
    dataAgeDays,
    pitchBadgeTone,
    startOutcome,
    startTone,
} from '@/lib/start-probability';
import type {
    StartFacts,
    StartOutcome,
    StartTone,
} from '@/lib/start-probability';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type { PlayerStatus, StartProbabilityEntry } from '@/types/models';

/**
 * FútbolFantasy's % as a 10-cell bar plus the figure (mock `.t-meter` +
 * `.t-pct`) — the one bar used by the match list, the team aside and the
 * manager roster. Muted (desaturated) while the data is stale.
 */
export function HqStartMeter({
    probability,
    status,
    size = 'md',
    muted = false,
    className,
}: {
    probability: number | null;
    status: PlayerStatus;
    size?: 'md' | 'sm';
    muted?: boolean;
    className?: string;
}) {
    const tone = startTone(probability, status);
    const lit = probability === null ? 0 : Math.round(probability / 10);
    const label =
        probability === null
            ? 'Sin probabilidad de FútbolFantasy'
            : `${probability} % de ser titular`;

    return (
        <span
            title={label}
            className={cn(
                'inline-flex shrink-0 items-center',
                size === 'sm' ? 'gap-1.5' : 'gap-2',
                muted && 'opacity-60 saturate-[.15]',
                className,
            )}
        >
            <span aria-hidden="true" className="inline-flex gap-0.5">
                {Array.from({ length: 10 }, (_, cell) => (
                    <i
                        key={cell}
                        className={cn(
                            'block',
                            size === 'sm'
                                ? 'h-[9px] w-[3px]'
                                : 'h-[11px] w-[5px]',
                            cell < lit
                                ? START_TONE_BG_CLASSES[tone]
                                : 'bg-hq-border-strong',
                        )}
                    />
                ))}
            </span>
            <b
                className={cn(
                    'text-right font-mono leading-none font-bold whitespace-nowrap tabular-nums',
                    size === 'sm' ? 'text-[11px]' : 'min-w-[34px] text-xs',
                    START_TONE_TEXT_CLASSES[tone],
                )}
            >
                <span className="sr-only">{label}</span>
                <span aria-hidden="true">
                    {probability === null ? '—' : `${probability}%`}
                </span>
            </b>
        </span>
    );
}

const OUTCOME_CLASSES: Record<StartOutcome, string> = {
    starter: 'bg-hq-lime/8 text-hq-lime',
    bench: 'text-hq-moss-dim',
    surprise: 'bg-hq-gold/8 text-hq-gold',
    dropped: 'bg-hq-ember/8 text-hq-ember',
};

const OUTCOME_TITLES: Record<StartOutcome, string> = {
    starter: 'Titular confirmado',
    bench: 'Suplente confirmado',
    surprise: 'No estaba en el XI probable de FútbolFantasy',
    dropped: 'Estaba en el XI probable de FútbolFantasy',
};

function outcomeLabel(
    outcome: StartOutcome,
    probability: number | null,
): string {
    const was = probability === null ? '' : ` · era ${probability} %`;

    switch (outcome) {
        case 'starter':
            return 'Titular';
        case 'bench':
            return 'Suplente';
        case 'surprise':
            return `Sorpresa${was}`;
        case 'dropped':
            return `Se cae${was}`;
    }
}

/**
 * Once the lineup is confirmed: lime "Titular", dim "Suplente", gold
 * "Sorpresa · era N %" and ember "Se cae · era N %" against FútbolFantasy's
 * probable XI. Nothing while unconfirmed.
 */
export function HqStartOutcomeChip({
    facts,
    className,
}: {
    facts: StartFacts;
    className?: string;
}) {
    const outcome = startOutcome(facts);

    if (outcome === null) {
        return null;
    }

    return (
        <span
            title={OUTCOME_TITLES[outcome]}
            className={cn(
                'inline-flex h-[17px] shrink-0 items-center border border-current px-[5px] font-mono text-[9.5px] leading-none font-bold tracking-[0.06em] whitespace-nowrap uppercase',
                OUTCOME_CLASSES[outcome],
                className,
            )}
        >
            {outcomeLabel(outcome, facts.probability)}
        </span>
    );
}

/** "● Probable" / "● Probable · antigua" / "● Confirmada". */
export function HqStartStateLabel({
    confirmed,
    stale,
}: {
    confirmed: boolean;
    stale: boolean;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 font-bold',
                confirmed
                    ? 'text-hq-lime'
                    : stale
                      ? 'text-hq-gold'
                      : 'text-hq-khaki',
            )}
        >
            <i
                aria-hidden="true"
                className="block h-[7px] w-[7px] rounded-full bg-current"
            />
            {confirmed ? 'Confirmada' : stale ? 'Probable · antigua' : 'Probable'}
        </span>
    );
}

/** Striped gold banner for data older than 48 h (mock `.t-stale`). */
export function HqStartStaleBanner({
    fetchedAt,
    now,
}: {
    fetchedAt: string;
    now: number;
}) {
    const days = Math.max(2, dataAgeDays(fetchedAt, now));

    return (
        <div
            role="status"
            className="flex flex-col gap-1 border-b border-hq-gold/35 bg-[repeating-linear-gradient(135deg,rgb(232_193_74/0.07)_0_8px,transparent_8px_16px)] px-3.5 py-2.5 font-mono text-xs leading-[1.45] text-hq-khaki sm:flex-row sm:items-start sm:gap-2.5 sm:px-4"
        >
            <b className="whitespace-nowrap text-hq-gold">
                ▲ Datos de hace {days} días
            </b>
            <span>
                No se ha podido leer FútbolFantasy desde el{' '}
                {formatMatchDateShort(fetchedAt)}. Los % pueden no reflejar
                la última rueda de prensa; las lesiones sí están al día.
            </span>
        </div>
    );
}

export interface StartSource {
    /** The team's short name, shown as the link text. */
    label: string;
    /** Its FútbolFantasy team page. */
    url: string;
}

/**
 * "Probabilidades: FútbolFantasy" with a link to each team page — on every
 * surface that shows a % — plus the lineup source once worldcup26 confirmed
 * one, and where injuries come from.
 */
export function HqStartAttribution({
    sources,
    confirmedByWorldcup26 = false,
    className,
}: {
    sources: StartSource[];
    confirmedByWorldcup26?: boolean;
    className?: string;
}) {
    const links = sources.filter(
        (source, index) =>
            source.url !== '' &&
            sources.findIndex((other) => other.url === source.url) === index,
    );

    return (
        <p
            className={cn(
                'flex flex-wrap items-center gap-x-2.5 gap-y-1 border-t border-hq-border px-3.5 py-2.5 font-mono text-[11px] leading-[1.4] text-hq-moss-dim sm:px-4',
                className,
            )}
        >
            {confirmedByWorldcup26 && (
                <>
                    <span>Alineación: worldcup26</span>
                    <span aria-hidden="true" className="text-hq-border-bright">
                        ·
                    </span>
                </>
            )}
            <span>
                Probabilidades: FútbolFantasy{' '}
                {links.map((source) => (
                    <a
                        key={source.url}
                        href={source.url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="mr-1.5 whitespace-nowrap text-hq-moss underline underline-offset-2 hover:text-hq-lime"
                    >
                        {source.label} ↗
                    </a>
                ))}
            </span>
            <span aria-hidden="true" className="text-hq-border-bright">
                ·
            </span>
            <span>Estado físico: LaLiga Fantasy</span>
        </p>
    );
}

const LEGEND: { tone: StartTone; label: string }[] = [
    { tone: 'high', label: '≥ 70 % titular fijo' },
    { tone: 'mid', label: '40–69 % duda' },
    { tone: 'low', label: '< 40 % suplente' },
    { tone: 'out', label: 'baja (LaLiga Fantasy)' },
];

/**
 * The tone key. `pitchNoteClassName` shows the pitch-only notes (lilac from
 * 90 %, dimmed = doubt) with that class — e.g. `hidden lg:inline-flex` where
 * the pitch is desktop only; omit it where there is no pitch.
 */
export function HqStartLegend({
    pitchNoteClassName,
    children,
}: {
    pitchNoteClassName?: string;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap gap-x-3 gap-y-1 px-3.5 py-2 font-mono text-[10.5px] leading-[1.2] text-hq-moss-dim sm:px-4">
            {LEGEND.map(({ tone, label }) => (
                <span key={tone} className="inline-flex items-center gap-[5px]">
                    <i
                        aria-hidden="true"
                        className={cn('block h-2 w-2', START_TONE_BG_CLASSES[tone])}
                    />
                    {label}
                </span>
            ))}
            {pitchNoteClassName !== undefined && (
                <>
                    <span className={cn('items-center gap-[5px]', pitchNoteClassName)}>
                        <i
                            aria-hidden="true"
                            className="block h-2 w-2 bg-hq-violet"
                        />
                        campo: ≥ 90 % en lila
                    </span>
                    <span className={pitchNoteClassName}>
                        campo: atenuado = duda (&lt; 60 %)
                    </span>
                </>
            )}
            {children}
        </div>
    );
}

/**
 * A probable (or confirmed) starter on a pitch (mock `.t-tok`): framed
 * photo with the % badge where the points chip usually sits — lilac from
 * 90 %, the tone scale below — dimmed with a dashed frame under 60 %. Once
 * confirmed the badge is ✓, or a gold "!" for a surprise starter. Links to
 * the player ficha.
 */
export function HqStartPitchToken({
    entry,
    confirmed,
    size = 'lg',
    muted = false,
}: {
    entry: StartProbabilityEntry;
    confirmed: boolean;
    size?: 'lg' | 'sm';
    muted?: boolean;
}) {
    const surprise = confirmed && startOutcome(entry) === 'surprise';
    const tone: StartTone = confirmed
        ? surprise
            ? 'mid'
            : 'high'
        : pitchBadgeTone(entry.probability, entry.player.status);
    const doubt = !confirmed && (entry.probability ?? 0) < DOUBT_THRESHOLD;
    const badge = confirmed
        ? surprise
            ? '!'
            : '✓'
        : entry.probability === null
          ? '—'
          : `${entry.probability}%`;
    const label = confirmed
        ? `${entry.player.nickname} · ${surprise ? 'titular sorpresa' : 'titular'}`
        : `${entry.player.nickname} · ${entry.probability === null ? 'sin dato' : `${entry.probability} % titular`}`;

    return (
        <Link
            href={playersShow(entry.player.id).url}
            aria-label={label}
            title={label}
            className={cn(
                'group flex flex-col items-center outline-none',
                size === 'lg' ? 'w-32' : 'w-[72px]',
            )}
        >
            <span
                className={cn(
                    'relative block',
                    size === 'lg' ? 'h-13 w-13' : 'h-11 w-11',
                )}
            >
                <span
                    className={cn(
                        'absolute inset-0 overflow-hidden border-[1.5px] bg-hq-well transition-colors group-hover:border-hq-lime group-focus-visible:border-hq-lime',
                        surprise ? 'border-hq-gold' : 'border-hq-paper/80',
                        doubt && 'border-dashed opacity-60',
                    )}
                >
                    <EntityImage
                        src={entry.player.image}
                        alt=""
                        fallback={User}
                        shape="square"
                        className="h-full w-full rounded-none bg-transparent object-cover"
                        style={{ objectPosition: 'center 25%' }}
                    />
                </span>
                {entry.player.status !== 'ok' && (
                    <HqStatusBadge
                        status={entry.player.status}
                        className="absolute -top-2 -left-3 z-10 bg-hq-ink px-[3px] py-0.5 text-[8.5px]"
                    />
                )}
                <span
                    className={cn(
                        'absolute -right-3.5 -bottom-[5px] z-10 inline-flex h-[18px] min-w-[30px] items-center justify-center border bg-hq-ink px-[3px] font-mono text-[11px] leading-none font-bold tabular-nums',
                        START_TONE_TEXT_CLASSES[tone],
                        START_TONE_BORDER_CLASSES[tone],
                        muted && 'opacity-60 saturate-[.15]',
                    )}
                >
                    {badge}
                </span>
            </span>
            <span
                className={cn(
                    'mt-1.5 block max-w-full truncate bg-[rgba(6,7,5,0.82)] px-1 py-0.5 font-mono leading-[1.1] font-medium',
                    size === 'lg' ? 'text-[11px]' : 'text-[10.5px]',
                    doubt ? 'text-hq-moss' : 'text-hq-paper',
                )}
            >
                {entry.player.nickname}
            </span>
        </Link>
    );
}
```

- [ ] **Step 3: Export the landscape pitch markings**

In `resources/js/components/hq-match-pitch.tsx`, rename `function PitchLines()` to `export function MatchPitchLines()`. Keep its docblock, and add "Shared with HqProbableMatchPitch." to it. Then replace the single use `<PitchLines />` with `<MatchPitchLines />`.

- [ ] **Step 4: Write the landscape probable-XI pitch**

`resources/js/components/hq-probable-match-pitch.tsx`:

```tsx
import { Shield } from 'lucide-react';
import { Fragment } from 'react';
import { EntityImage } from '@/components/entity-image';
import { MatchPitchLines } from '@/components/hq-match-pitch';
import { HqStartPitchToken } from '@/components/hq-start-probability';
import { landscapeSlots, splitStartEntries } from '@/lib/start-probability';
import { cn } from '@/lib/utils';
import type { StartProbabilityTeamBlock, Team } from '@/types/models';

type Side = 'local' | 'guest';

function SideTag({
    team,
    label,
    side,
}: {
    team: Team;
    label: string;
    side: Side;
}) {
    const crest = (
        <EntityImage
            src={team.logo}
            alt=""
            fallback={Shield}
            shape="square"
            className="h-3.5 w-3.5 rounded-none bg-transparent"
        />
    );

    return (
        <span
            className={cn(
                'absolute top-2 z-10 flex items-center gap-1.5 border border-hq-border-bright bg-hq-ink px-1.5 py-1 font-mono text-[10.5px] leading-none font-bold tracking-[0.05em] text-hq-moss uppercase',
                side === 'local' ? 'left-2' : 'right-2',
            )}
        >
            {side === 'local' && crest}
            {label}
            {side === 'guest' && crest}
        </span>
    );
}

/**
 * Both probable — or confirmed — XIs on HqMatchPitch's landscape pitch
 * (local attacking right), each player on the line of his fantasy
 * position with the strongest % in the middle. Desktop only, like
 * HqMatchPitch.
 */
export function HqProbableMatchPitch({
    local,
    guest,
    localTeam,
    guestTeam,
}: {
    local: StartProbabilityTeamBlock | null;
    guest: StartProbabilityTeamBlock | null;
    localTeam: Team;
    guestTeam: Team;
}) {
    const sides: {
        side: Side;
        team: Team;
        block: StartProbabilityTeamBlock | null;
    }[] = [
        { side: 'local', team: localTeam, block: local },
        { side: 'guest', team: guestTeam, block: guest },
    ];

    return (
        <div className="relative aspect-[16/9.2] w-full overflow-hidden border-b border-hq-border bg-hq-pitch">
            <MatchPitchLines />
            {sides.map(({ side, team, block }) => {
                const confirmed =
                    block !== null && block.confirmed_source !== null;
                const muted = block?.is_stale ?? false;
                const slots =
                    block === null
                        ? []
                        : landscapeSlots(
                              splitStartEntries(block.players, confirmed)
                                  .starters,
                              side,
                          );
                const label =
                    block === null
                        ? 'Sin datos'
                        : confirmed
                          ? 'XI confirmado'
                          : 'XI probable';

                return (
                    <Fragment key={side}>
                        <SideTag team={team} label={label} side={side} />
                        {slots.map(({ entry, left, top }) => (
                            <div
                                key={entry.player.id}
                                className="absolute z-[2] -translate-x-1/2 -translate-y-1/2"
                                style={{ left: `${left}%`, top: `${top}%` }}
                            >
                                <HqStartPitchToken
                                    entry={entry}
                                    confirmed={confirmed}
                                    muted={muted}
                                />
                            </div>
                        ))}
                    </Fragment>
                );
            })}
        </div>
    );
}
```

- [ ] **Step 5: Write the match page section**

`resources/js/components/hq-start-probabilities-section.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { Shield, User } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { EntityImage } from '@/components/entity-image';
import { HqPositionTag } from '@/components/hq-position-tag';
import { HqProbableMatchPitch } from '@/components/hq-probable-match-pitch';
import { HqChannelHeader } from '@/components/hq-section';
import {
    HqStartAttribution,
    HqStartLegend,
    HqStartMeter,
    HqStartOutcomeChip,
    HqStartStaleBanner,
    HqStartStateLabel,
} from '@/components/hq-start-probability';
import { HqStatusBadge } from '@/components/hq-status-badge';
import type { FixtureViewMode } from '@/lib/fixture-view-mode';
import { formatMatchDay } from '@/lib/format';
import {
    averageProbability,
    formatDataAge,
    isUnavailable,
    splitStartEntries,
} from '@/lib/start-probability';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type {
    Fixture,
    FixtureStartProbabilities,
    StartProbabilityEntry,
    StartProbabilityTeamBlock,
    Team,
} from '@/types/models';

type Side = 'local' | 'guest';

function ColumnHead({ team, children }: { team: Team; children: ReactNode }) {
    return (
        <div className="flex items-center gap-2 border-b border-hq-border px-3.5 py-[9px] font-mono text-[11px] leading-none font-bold tracking-[0.08em] text-hq-moss uppercase sm:px-4">
            <EntityImage
                src={team.logo}
                alt=""
                fallback={Shield}
                shape="square"
                className="h-4 w-4 shrink-0 rounded-none bg-transparent"
            />
            <span className="truncate">{team.main_name}</span>
            <span className="ml-auto flex items-center gap-1.5 font-medium whitespace-nowrap text-hq-moss-dim normal-case">
                {children}
            </span>
        </div>
    );
}

function SubHead({ label, count }: { label: string; count: number }) {
    return (
        <div className="flex items-center gap-2 border-b border-hq-border-strong px-3.5 pt-3 pb-[7px] font-mono text-[10.5px] leading-none font-bold tracking-[0.1em] text-hq-moss uppercase sm:px-4">
            {label}
            <span className="text-hq-moss-dim">{count}</span>
        </div>
    );
}

/**
 * One player (mock `.t-lrow`): photo with the position tag, the name and
 * our status — plus "FF aún le da N %" for a baja FútbolFantasy still
 * rates — then the bar and %, or the outcome chip once confirmed.
 */
function StartRow({
    entry,
    confirmed,
    dim = false,
    muted = false,
}: {
    entry: StartProbabilityEntry;
    confirmed: boolean;
    dim?: boolean;
    muted?: boolean;
}) {
    const unavailable = isUnavailable(entry.player.status);

    return (
        <div
            className={cn(
                'grid grid-cols-[36px_minmax(0,1fr)_auto] items-center gap-2.5 border-b border-hq-border px-3.5 py-[7px] transition-colors hover:bg-hq-panel sm:px-4',
                dim && 'opacity-60',
            )}
        >
            <span className="relative block h-9 w-9">
                <EntityImage
                    src={entry.player.image}
                    alt=""
                    fallback={User}
                    shape="square"
                    className="h-9 w-9 rounded-none border border-hq-border-strong bg-hq-well object-cover"
                    style={{ objectPosition: 'center 20%' }}
                />
                <HqPositionTag
                    position={entry.player.position}
                    className="absolute -bottom-1.5 left-1/2 -translate-x-1/2 bg-hq-ink px-[3px] py-0.5 text-[8.5px]"
                />
            </span>
            <div className="min-w-0">
                <Link
                    href={playersShow(entry.player.id).url}
                    className="block truncate text-[13.5px] leading-[1.2] font-bold text-hq-paper hover:text-hq-lime"
                >
                    {entry.player.nickname}
                </Link>
                {entry.player.status !== 'ok' && (
                    <div className="mt-1 flex flex-wrap items-center gap-1.5 font-mono text-[11px] leading-[1.2] text-hq-moss-dim">
                        <HqStatusBadge status={entry.player.status} />
                        {unavailable && (entry.probability ?? 0) > 0 && (
                            <span>FF aún le da {entry.probability} %</span>
                        )}
                    </div>
                )}
            </div>
            <div className="flex items-center justify-end">
                {confirmed ? (
                    <HqStartOutcomeChip facts={entry} />
                ) : (
                    <HqStartMeter
                        probability={entry.probability}
                        status={entry.player.status}
                        muted={muted}
                    />
                )}
            </div>
        </div>
    );
}

function TeamColumn({
    team,
    block,
    now,
    starterRowsClassName,
}: {
    team: Team;
    block: StartProbabilityTeamBlock | null;
    now: number;
    starterRowsClassName: string;
}) {
    if (block === null) {
        return (
            <>
                <ColumnHead team={team}>sin datos</ColumnHead>
                <p className="px-3.5 py-3 font-mono text-xs leading-[1.45] text-hq-moss sm:px-4">
                    FútbolFantasy aún no tiene la alineación de este equipo.
                </p>
            </>
        );
    }

    const confirmed = block.confirmed_source !== null;
    const muted = block.is_stale;
    const { starters, bench, rest, out } = splitStartEntries(
        block.players,
        confirmed,
    );
    const average = averageProbability(starters);

    return (
        <>
            <ColumnHead team={team}>
                {confirmed ? (
                    'XI confirmado'
                ) : (
                    <>
                        {average !== null && (
                            <span className="max-md:hidden">
                                media XI {average} % ·
                            </span>
                        )}
                        {block.fetched_at && (
                            <span
                                className={cn(
                                    muted && 'font-bold text-hq-gold',
                                )}
                            >
                                {muted && '▲ datos de '}
                                {formatDataAge(block.fetched_at, now)}
                            </span>
                        )}
                    </>
                )}
            </ColumnHead>
            <div className={starterRowsClassName}>
                {starters.map((entry) => (
                    <StartRow
                        key={entry.player.id}
                        entry={entry}
                        confirmed={confirmed}
                        muted={muted}
                    />
                ))}
            </div>
            <SubHead
                label={confirmed ? 'Suplentes destacados' : 'Banquillo y dudas'}
                count={bench.length}
            />
            {bench.map((entry) => (
                <StartRow
                    key={entry.player.id}
                    entry={entry}
                    confirmed={confirmed}
                    dim={!confirmed}
                    muted={muted}
                />
            ))}
            {rest.length > 0 && (
                <p className="border-b border-hq-border px-3.5 py-2 font-mono text-[11px] leading-[1.45] text-hq-moss-dim sm:px-4">
                    <b className="font-semibold text-hq-moss">
                        +{rest.length} &lt; 30 %
                    </b>
                    : {rest.map((entry) => entry.player.nickname).join(', ')}
                </p>
            )}
            {out.length > 0 && (
                <>
                    <SubHead label="Bajas" count={out.length} />
                    {out.map((entry) => (
                        <StartRow
                            key={entry.player.id}
                            entry={entry}
                            confirmed={confirmed}
                            dim
                            muted={muted}
                        />
                    ))}
                </>
            )}
        </>
    );
}

interface HqStartProbabilitiesSectionProps {
    probabilities: FixtureStartProbabilities;
    fixture: Fixture;
    viewMode: FixtureViewMode;
}

/**
 * The match ficha before kickoff (variant A). FútbolFantasy's probable XIs —
 * or the confirmed ones — on the landscape pitch (desktop "campo" view) and
 * as two columns of rows with the 10-cell bar ("lista" view; phones always).
 * Under each XI: bench and doubts (≥ 30 %), "+N < 30 %" and the bajas from
 * our status. Then the key and the attribution.
 */
export function HqStartProbabilitiesSection({
    probabilities,
    fixture,
    viewMode,
}: HqStartProbabilitiesSectionProps) {
    const now = useNow(60_000);
    const [mobileSide, setMobileSide] = useState<Side>('local');
    const sides: {
        side: Side;
        team: Team;
        block: StartProbabilityTeamBlock | null;
    }[] = [
        { side: 'local', team: fixture.local_team, block: probabilities.local },
        { side: 'guest', team: fixture.guest_team, block: probabilities.guest },
    ];
    const blocks = sides.flatMap(({ block }) => (block === null ? [] : [block]));
    const allConfirmed =
        blocks.length > 0 &&
        blocks.every((block) => block.confirmed_source !== null);
    const staleBlock = blocks.find(
        (block) => block.is_stale && block.fetched_at !== null,
    );
    const showPitch = viewMode === 'pitch';

    return (
        <section className="border-b border-hq-border">
            <HqChannelHeader
                code="XI"
                title={
                    allConfirmed ? 'Alineación confirmada' : 'Alineación probable'
                }
                action={
                    <>
                        <HqStartStateLabel
                            confirmed={allConfirmed}
                            stale={staleBlock !== undefined}
                        />
                        <span>
                            J{fixture.week_number} ·{' '}
                            {formatMatchDay(fixture.date)}
                        </span>
                    </>
                }
            />
            {staleBlock?.fetched_at && (
                <HqStartStaleBanner fetchedAt={staleBlock.fetched_at} now={now} />
            )}
            {showPitch && (
                <div className="hidden lg:block">
                    <HqProbableMatchPitch
                        local={probabilities.local}
                        guest={probabilities.guest}
                        localTeam={fixture.local_team}
                        guestTeam={fixture.guest_team}
                    />
                </div>
            )}
            <div
                role="group"
                aria-label="Equipo a mostrar"
                className="flex border-b border-hq-border-strong md:hidden"
            >
                {sides.map(({ side, team }) => (
                    <button
                        key={side}
                        type="button"
                        aria-pressed={mobileSide === side}
                        onClick={() => setMobileSide(side)}
                        className={cn(
                            '-mb-px min-h-11 flex-1 border-b-2 font-mono text-[11.5px] font-bold tracking-[0.07em] uppercase',
                            mobileSide === side
                                ? 'border-hq-lime text-hq-lime'
                                : 'border-transparent text-hq-moss',
                        )}
                    >
                        {team.main_name}
                    </button>
                ))}
            </div>
            <div className="grid grid-cols-1 md:grid-cols-2">
                {sides.map(({ side, team, block }, index) => (
                    <div
                        key={side}
                        className={cn(
                            'min-w-0',
                            index === 1 && 'md:border-l md:border-hq-border',
                            mobileSide !== side && 'max-md:hidden',
                        )}
                    >
                        <TeamColumn
                            team={team}
                            block={block}
                            now={now}
                            starterRowsClassName={showPitch ? 'lg:hidden' : ''}
                        />
                    </div>
                ))}
            </div>
            {!allConfirmed && (
                <HqStartLegend
                    pitchNoteClassName={
                        showPitch ? 'hidden lg:inline-flex' : undefined
                    }
                />
            )}
            <HqStartAttribution
                sources={blocks.map((block) => ({
                    label: block.team.short_name,
                    url: block.source_url,
                }))}
                confirmedByWorldcup26={blocks.some(
                    (block) => block.confirmed_source === 'worldcup26',
                )}
            />
        </section>
    );
}
```

- [ ] **Step 6: Render it on the match page**

In `resources/js/pages/fixtures/show.tsx`:

1. Add the import, alphabetically after `HqScrollRow`/`hq-section`:

```tsx
import { HqStartProbabilitiesSection } from '@/components/hq-start-probabilities-section';
```

2. Add `FixtureStartProbabilities,` to the `import type { … } from '@/types/models'` list, and to `FixtureShowProps`:

```tsx
    startProbabilities: FixtureStartProbabilities | null;
```

3. Add `startProbabilities,` to the destructured props of `FixtureShow`, and after `const hasLineups = …;` add:

```tsx
    // Before kickoff (and until a live lineup takes over) the section shows
    // FútbolFantasy's probable XIs, or the confirmed ones — its own campo/lista toggle too.
    const showsStartProbabilities = !hasLineups && startProbabilities !== null;
```

4. Replace `{(isLive || hasLineups) && (` with `{(isLive || hasLineups || showsStartProbabilities) && (`, and inside it replace `{hasLineups && (` (the one wrapping `<ViewModeToggle`) with `{(hasLineups || showsStartProbabilities) && (`.

5. Replace

```tsx
                {!hasLineups ? (
                    <HqEmptyState
                        glyph="⚽"
                        title="Todavía no hay datos de jugadores"
                    >
                        Cuando empiece el partido aparecerán aquí los puntos de
                        cada jugador
                    </HqEmptyState>
                ) : (
```

with

```tsx
                {!hasLineups ? (
                    startProbabilities ? (
                        <HqStartProbabilitiesSection
                            probabilities={startProbabilities}
                            fixture={fixture}
                            viewMode={viewMode}
                        />
                    ) : (
                        <HqEmptyState
                            glyph="⚽"
                            title="Todavía no hay datos de jugadores"
                        >
                            Cuando empiece el partido aparecerán aquí los
                            puntos de cada jugador
                        </HqEmptyState>
                    )
                ) : (
```

- [ ] **Step 7: Format, lint, type-check, build**

Run: `npx prettier --write resources/js/lib/start-probability.ts resources/js/components/hq-start-probability.tsx resources/js/components/hq-match-pitch.tsx resources/js/components/hq-probable-match-pitch.tsx resources/js/components/hq-start-probabilities-section.tsx resources/js/pages/fixtures/show.tsx`
Then: `npm run lint:check`, `npm run types:check`, `npm run build`
Expected: no errors.

- [ ] **Step 8: Check it in the browser**

The dev server is off, so use the built assets. Open a match of the next jornada served by Herd, for example `http://comando-lechuga.test/partidos/80` (ALA–ATM J8, fetched at the Task 4 checkpoint). Check:
- Desktop, campo: both XIs on the landscape pitch; % badges lilac at ≥ 90, dashed and dimmed under 60.
- Desktop, lista (toggle): two columns of XI rows with the 10-cell bar.
- Under both views: "Banquillo y dudas", the "+N < 30 %" line and "Bajas". The attribution links open the FF team pages.
- At 390 px width: team tabs and the list only.

Screenshot both views for the user.

- [ ] **Step 9: Run the PHP suite and commit**

Run: `herd php artisan test --compact`
Expected: PASS.

```bash
git add resources/js/lib/start-probability.ts resources/js/components/hq-start-probability.tsx resources/js/components/hq-match-pitch.tsx resources/js/components/hq-probable-match-pitch.tsx resources/js/components/hq-start-probabilities-section.tsx resources/js/pages/fixtures/show.tsx
git commit -m "feat: show the probable or confirmed XIs on the match ficha" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 8: Team page, the probable-XI half pitch in the jornada aside (variant B)

**Files:**
- Create: `resources/js/components/hq-probable-half-pitch.tsx`
- Modify: `resources/js/pages/teams/show.tsx`

**Interfaces:**
- Consumes: `TeamNextStartProbabilities` and the `teams/show` prop `startProbabilities` (Task 6). From Task 7: `halfPitchSlots`, `splitStartEntries`, `startOutcome`, `startTone`, `isUnavailable`, `formatDataAge`, `START_TONE_TEXT_CLASSES` from `@/lib/start-probability`, plus `HqStartPitchToken`, `HqStartOutcomeChip`, `HqStartStateLabel`, `HqStartStaleBanner`, `HqStartLegend`, `HqStartAttribution` from `@/components/hq-start-probability`.
- Produces: `HqProbableHalfPitch({ probabilities }: { probabilities: TeamNextStartProbabilities })`.

**Behaviour.** The aside shows the half pitch instead of "Sin alineación" only in one case. The selected jornada must be the team's next match (`startProbabilities.week_number === selectedWeek`), and there must be no worldcup26 lineup yet for it (`lineupForWeek` absent). Once a worldcup26 lineup exists, the existing real pitch takes over.

- [ ] **Step 1: Write the half pitch**

`resources/js/components/hq-probable-half-pitch.tsx`:

```tsx
import { Link } from '@inertiajs/react';
import { HqPositionTag } from '@/components/hq-position-tag';
import {
    HqStartAttribution,
    HqStartLegend,
    HqStartOutcomeChip,
    HqStartPitchToken,
    HqStartStaleBanner,
    HqStartStateLabel,
} from '@/components/hq-start-probability';
import { HqStatusBadge } from '@/components/hq-status-badge';
import {
    START_TONE_TEXT_CLASSES,
    formatDataAge,
    halfPitchSlots,
    isUnavailable,
    splitStartEntries,
    startOutcome,
    startTone,
} from '@/lib/start-probability';
import { useNow } from '@/lib/use-now';
import { cn } from '@/lib/utils';
import { show as playersShow } from '@/routes/players';
import type {
    StartProbabilityEntry,
    TeamNextStartProbabilities,
} from '@/types/models';

/** Half-pitch markings (mock PITCH_SVG): halfway line at the top, own box at the bottom. */
function HalfPitchLines() {
    return (
        <svg
            aria-hidden="true"
            viewBox="0 0 300 355"
            preserveAspectRatio="none"
            className="absolute inset-2 h-[calc(100%-16px)] w-[calc(100%-16px)] fill-none stroke-hq-pitch-line [stroke-width:1.2] [&>*]:[vector-effect:non-scaling-stroke]"
        >
            <rect x="0" y="0" width="300" height="355" />
            <path d="M110 0 A40 40 0 0 0 190 0" />
            <rect x="60" y="283" width="180" height="72" />
            <rect x="110" y="330" width="80" height="25" />
            <path d="M120 283 A34 34 0 0 1 180 283" />
        </svg>
    );
}

/** A labelled row of player pills (mock `.t-strip`): position, name, status and the % — or "Se cae" once confirmed. */
function StartPills({
    label,
    entries,
    confirmed,
}: {
    label: string;
    entries: StartProbabilityEntry[];
    confirmed: boolean;
}) {
    if (entries.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1.5 border-t border-hq-border px-3.5 py-2.5 sm:px-4">
            <span className="w-full font-mono text-[10px] leading-none font-bold tracking-[0.1em] text-hq-moss-dim uppercase">
                {label}
            </span>
            {entries.map((entry) => (
                <Link
                    key={entry.player.id}
                    href={playersShow(entry.player.id).url}
                    className={cn(
                        'inline-flex h-6 items-center gap-1.5 border px-[7px] font-mono text-[11.5px] leading-none font-semibold whitespace-nowrap text-hq-paper hover:border-hq-lime',
                        isUnavailable(entry.player.status)
                            ? 'border-hq-live/45'
                            : 'border-hq-border-strong',
                    )}
                >
                    <HqPositionTag
                        position={entry.player.position}
                        className="px-[3px] py-0.5 text-[8.5px]"
                    />
                    {entry.player.nickname}
                    <HqStatusBadge
                        status={entry.player.status}
                        className="px-1 py-0.5 text-[9px]"
                    />
                    {confirmed ? (
                        startOutcome(entry) === 'dropped' && (
                            <HqStartOutcomeChip facts={entry} />
                        )
                    ) : (
                        <b
                            className={cn(
                                'font-mono text-[11px] font-bold tabular-nums',
                                START_TONE_TEXT_CLASSES[
                                    startTone(entry.probability, entry.player.status)
                                ],
                            )}
                        >
                            {entry.probability === null
                                ? '—'
                                : `${entry.probability}%`}
                        </b>
                    )}
                </Link>
            ))}
        </div>
    );
}

/**
 * The team ficha's jornada aside before the match (variant B):
 * FútbolFantasy's probable XI — or the confirmed one — on a half pitch
 * attacking up, then the doubts (≥ 30 %) and the bajas from our status as
 * pills, the key and the attribution.
 */
export function HqProbableHalfPitch({
    probabilities,
}: {
    probabilities: TeamNextStartProbabilities;
}) {
    const now = useNow(60_000);
    const confirmed = probabilities.confirmed_source !== null;
    const { starters, bench, out } = splitStartEntries(
        probabilities.players,
        confirmed,
    );

    return (
        <div>
            {probabilities.is_stale && probabilities.fetched_at && (
                <HqStartStaleBanner
                    fetchedAt={probabilities.fetched_at}
                    now={now}
                />
            )}
            <div className="flex items-center justify-between gap-2 px-3.5 pt-2.5 font-mono text-[10.5px] leading-[1.2] font-medium tracking-[0.07em] text-hq-moss-dim uppercase sm:px-4">
                <span>
                    {confirmed ? 'Once confirmado' : 'Once probable'} · J
                    {probabilities.week_number}{' '}
                    {probabilities.is_home ? 'vs' : '@'}{' '}
                    {probabilities.opponent.short_name}
                </span>
                <HqStartStateLabel
                    confirmed={confirmed}
                    stale={probabilities.is_stale}
                />
            </div>
            <div className="p-3.5 sm:p-4">
                <div className="relative mx-auto aspect-[3/3.3] w-full max-w-[360px] overflow-hidden border border-hq-border bg-hq-pitch">
                    <HalfPitchLines />
                    {halfPitchSlots(starters).map(({ entry, left, top }) => (
                        <div
                            key={entry.player.id}
                            className="absolute z-[2] -translate-x-1/2 -translate-y-1/2"
                            style={{ left: `${left}%`, top: `${top}%` }}
                        >
                            <HqStartPitchToken
                                entry={entry}
                                confirmed={confirmed}
                                size="sm"
                                muted={probabilities.is_stale}
                            />
                        </div>
                    ))}
                </div>
            </div>
            <StartPills
                label={confirmed ? 'Suplentes destacados' : 'Dudas (≥ 30 %)'}
                entries={bench}
                confirmed={confirmed}
            />
            <StartPills label="Bajas" entries={out} confirmed={confirmed} />
            {!confirmed && (
                <HqStartLegend pitchNoteClassName="inline-flex">
                    {probabilities.fetched_at && (
                        <span>{formatDataAge(probabilities.fetched_at, now)}</span>
                    )}
                </HqStartLegend>
            )}
            <HqStartAttribution
                sources={[
                    {
                        label: probabilities.team.short_name,
                        url: probabilities.source_url,
                    },
                ]}
                confirmedByWorldcup26={
                    probabilities.confirmed_source === 'worldcup26'
                }
            />
        </div>
    );
}
```

- [ ] **Step 2: Use it in the jornada aside**

In `resources/js/pages/teams/show.tsx`:

1. Add the import after `HqPositionTag`, which keeps the alphabetical order:

```tsx
import { HqProbableHalfPitch } from '@/components/hq-probable-half-pitch';
```

2. Add `TeamNextStartProbabilities,` to the `import type { … } from '@/types/models'` list. Add `startProbabilities: TeamNextStartProbabilities | null;` to `TeamShowProps`, and `startProbabilities,` to the destructured props.

3. Replace

```tsx
                        ) : (
                            <HqEmptyState
                                glyph="▦"
                                title="Sin alineación"
                                className="mx-auto max-w-[360px] sm:mx-auto"
                            >
                                Alineación aún no disponible en esa jornada.
                            </HqEmptyState>
                        )}
```

with

```tsx
                        ) : startProbabilities &&
                          startProbabilities.week_number === selectedWeek ? (
                            <HqProbableHalfPitch
                                probabilities={startProbabilities}
                            />
                        ) : (
                            <HqEmptyState
                                glyph="▦"
                                title="Sin alineación"
                                className="mx-auto max-w-[360px] sm:mx-auto"
                            >
                                Alineación aún no disponible en esa jornada.
                            </HqEmptyState>
                        )}
```

- [ ] **Step 3: Format, lint, type-check, build**

Run: `npx prettier --write resources/js/components/hq-probable-half-pitch.tsx resources/js/pages/teams/show.tsx`, then `npm run lint:check`, `npm run types:check`, `npm run build`
Expected: no errors.

- [ ] **Step 4: Check it in the browser**

Open a team fetched at the checkpoint, e.g. `http://comando-lechuga.test/equipos/31`. The aside opens on the upcoming jornada, which has no lineup yet. Check:
- A probable-XI half pitch appears where "Sin alineación" was. Badges are lilac at ≥ 90 and dashed under 60. The "Dudas (≥ 30 %)" and "Bajas" pills, the key with the data age, and the attribution link all show.
- A past jornada still shows its real lineup, and a jornada further ahead still shows "Sin alineación".

Screenshot for the user.

- [ ] **Step 5: Commit**

```bash
git add resources/js/components/hq-probable-half-pitch.tsx resources/js/pages/teams/show.tsx
git commit -m "feat: show the probable XI in the team ficha's jornada aside" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Manager page, bar and % under each next match (variant A)

**Files:**
- Modify: `resources/js/pages/season-managers/roster-list.tsx`
- Modify: `resources/js/pages/season-managers/show.tsx`

**Interfaces:**
- Consumes: `Player.next_start: PlayerNextStart | null` (Task 6). From Task 7: `HqStartMeter` (the same 10-cell bar as the match list, `size="sm"`), `HqStartOutcomeChip`, `HqStartAttribution` and `HqStartStaleBanner` from `@/components/hq-start-probability`, and `useNow` from `@/lib/use-now`.
- Produces: under each roster row's next-match cell ("Próximos"), the bar + % for that match, or Titular/Suplente once confirmed, and nothing without data. The roster header shows `J{n} · X/Y XI prob.`, where n is the soonest jornada among the roster's next starts. X counts the roster players (coaches excluded) in the confirmed XI, or in FF's probable XI while unconfirmed; Y is the roster size without coaches. A stale banner appears when any start is older than 48 h, and the attribution sits under the roster.

- [ ] **Step 1: Add the bar under the next-match cell**

In `resources/js/pages/season-managers/roster-list.tsx`:

1. Add the import after `HqRecentScores`:

```tsx
import {
    HqStartMeter,
    HqStartOutcomeChip,
} from '@/components/hq-start-probability';
```

and change the model import to:

```tsx
import type {
    PlayerNextStart,
    PlayerPosition,
    PlayerStatus,
    ManagerPlayer,
} from '@/types/models';
```

2. Add this component above `RosterRow`:

```tsx
/**
 * Under the next-match cell: FútbolFantasy's % for that match as the
 * shared 10-cell bar (the match list's), or the confirmed Titular /
 * Suplente. Nothing without data.
 */
function RosterNextStart({
    start,
    status,
}: {
    start: PlayerNextStart | null;
    status: PlayerStatus;
}) {
    if (start === null) {
        return null;
    }

    if (start.confirmed_starter !== null) {
        return <HqStartOutcomeChip facts={start} className="self-start" />;
    }

    return (
        <HqStartMeter
            probability={start.probability}
            status={status}
            size="sm"
            muted={start.is_stale}
        />
    );
}
```

3. In `RosterRow`, replace the next-fixtures cell

```tsx
            <div className="col-span-2 flex flex-col gap-[5px] lg:col-span-1">
                <MobileCaption>Próximos</MobileCaption>
                <HqNextFixtures
                    fixtures={entry.player.next_fixtures}
                    size="sm"
                />
            </div>
```

with

```tsx
            <div className="col-span-2 flex flex-col gap-[5px] lg:col-span-1">
                <MobileCaption>Próximos</MobileCaption>
                <HqNextFixtures
                    fixtures={entry.player.next_fixtures}
                    size="sm"
                />
                <RosterNextStart
                    start={entry.player.next_start ?? null}
                    status={entry.player.status}
                />
            </div>
```

- [ ] **Step 2: Add the header summary, the stale banner and the attribution**

In `resources/js/pages/season-managers/show.tsx`:

1. Add the imports, `import { HqStartAttribution, HqStartStaleBanner } from '@/components/hq-start-probability';` after the `HqChannelHeader, HqSection` import, and `import { useNow } from '@/lib/use-now';` after the `formatCurrency` import.

2. After `const rosterValueDifference = …;` add:

```tsx
    const now = useNow(60_000);
    const nextStarts = roster.flatMap((entry) =>
        entry.player.next_start ? [entry.player.next_start] : [],
    );
    const outfieldRoster = roster.filter(
        (entry) => entry.player.position !== 'coach',
    );
    const startWeek =
        nextStarts.length > 0
            ? Math.min(...nextStarts.map((start) => start.week_number))
            : null;
    const probableStarters = outfieldRoster.filter(({ player }) =>
        player.next_start
            ? (player.next_start.confirmed_starter ??
              player.next_start.predicted_starter)
            : false,
    ).length;
    const oldestStaleStart = nextStarts
        .filter((start) => start.is_stale && start.fetched_at !== null)
        .sort((a, b) => (a.fetched_at ?? '').localeCompare(b.fetched_at ?? ''))[0];
```

3. In the "Plantilla actual" `HqChannelHeader` `action`, add as the **first** child of the fragment:

```tsx
                                {startWeek !== null && (
                                    <span className="font-mono whitespace-nowrap">
                                        J{startWeek} ·{' '}
                                        <b className="font-bold text-hq-lime">
                                            {probableStarters}/
                                            {outfieldRoster.length}
                                        </b>{' '}
                                        XI prob.
                                    </span>
                                )}
```

4. Replace `<RosterList roster={roster} />` with:

```tsx
                    {oldestStaleStart?.fetched_at && (
                        <HqStartStaleBanner
                            fetchedAt={oldestStaleStart.fetched_at}
                            now={now}
                        />
                    )}
                    <RosterList roster={roster} />
                    {nextStarts.length > 0 && (
                        <HqStartAttribution
                            sources={nextStarts.map((start) => ({
                                label: start.team_short_name,
                                url: start.source_url,
                            }))}
                            confirmedByWorldcup26={nextStarts.some(
                                (start) => start.confirmed_source === 'worldcup26',
                            )}
                        />
                    )}
```

- [ ] **Step 3: Format, lint, type-check, build**

Run: `npx prettier --write resources/js/pages/season-managers/roster-list.tsx resources/js/pages/season-managers/show.tsx`, then `npm run lint:check`, `npm run types:check`, `npm run build`
Expected: no errors.

- [ ] **Step 4: Check it in the browser**

Open a manager, e.g. `http://comando-lechuga.test/managers/3`. Check:
- The header reads "J8 · X/Y XI prob.".
- Under each roster player's next-match crests is the 10-cell bar + %, the same bar as on the match page, at the small size.
- Players without data show nothing.
- The attribution under the roster links one page per team.
- At 390 px the bar sits under "Próximos" in the folded row.

Screenshot for the user.

- [ ] **Step 5: Run everything and commit**

Run: `herd php artisan test --compact`, `herd composer phpstan`
Expected: PASS / no errors.

```bash
git add resources/js/pages/season-managers/roster-list.tsx resources/js/pages/season-managers/show.tsx
git commit -m "feat: show each roster player's start probability on the manager ficha" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage.**
- Source, UA, gzip, 10–30 s and one attempt: Task 4.
- Dedupe by `ffId`, the pre-season missing %, `Titular`/`Suplente`: Task 2.
- Injury status from `players.status`: Tasks 6, 7 and 8 (`isUnavailable`, bajas).
- Attribution on every surface: `HqStartAttribution` in Tasks 7, 8 and 9.
- Linking rules 1–4, ties, the manual map, unlinked logged: Tasks 3 and 4.
- The 20-entry team map keyed by `teams.fantasy_id`, RMD vs RMA: Task 1.
- Storage columns, unique pair, upsert: Tasks 1 and 4.
- "Which fixture" (team + J{n}, rival sanity check, no heading → skip): Task 4.
- Surfaces read the fixture or the next fixture: Task 6.
- Sync every 10 min, 48 h / 6 h / never after kickoff, failure keeps rows: Task 4.
- Live sync from 1 h 30 min: Task 5.
- Confirmed precedence worldcup26 > FF: Task 6.
- Stale after 48 h: Task 6 flag, Tasks 7–9 UI.
- Display variants and lilac at ≥ 90: Tasks 7–9.
- Max bid untouched: no task modifies it.
- Testing list: parser (Task 2), linker (Task 3), command and live sync (Tasks 4–5), controllers (Task 6).

**Placeholders.** None. Every code step carries its code, and every command has its expected result. `PLAYER_MAP` starts empty on purpose; the checkpoint fills it.

**Type consistency.** The following names were checked across tasks:
- PHP: `FutbolFantasyPlayer::$futbolfantasyId`, `FutbolFantasyTeamPage::rivalCode()`, `FutbolFantasyLinkRule` cases, and the `link()` return type.
- PHP to TS: `StartTeamBlock` keys are identical to `StartProbabilityTeamBlock`, and the `PlayerNextStart` shape is the same in PHP (`Player::$next_start` docblock and `StartProbabilities`) and in TS.
- TS: `StartFacts` is accepted by `HqStartOutcomeChip` from both an entry and a `PlayerNextStart`. `HqStartMeter` is the only bar component, used by Tasks 7, 8 and 9.

**Review Focus.** The five lines are pinned as follows:
- Confirmation keeps the %: Task 4, `keeps the last predicted % and XI…`.
- Two FF players matching one of ours: Task 3, `never links two FútbolFantasy players…`.
- A transferred player linked by stored id: Task 3, `links by the stored FútbolFantasy id first, even on another team`.
- A kicked-off jornada is not overwritten: Task 4, `keeps the rows of a jornada that has already kicked off`.
- A one-sided fixture: Task 6, `keeps a side without data empty…`, rendered as "sin datos" in Task 7.

**Spec rulings made here.** Tell the user about these.
1. **`predicted_starter` column (bool, default false)** is added to `fixture_lineup_probabilities`. The spec's column list doesn't name it, but the pitch must show *FF's* probable XI. Deriving it from the % is ambiguous because ties at 50 % cross the XI line on real pages. The value is FF's own `data-onceFF`.
2. **FF code map for the rival check** (`FutbolFantasyTeams::CODES`, keyed by FF slug) sits beside the slug map, which is keyed by `teams.fantasy_id` as the coordinator asked. `data-rival` uses FF codes, and three of them differ from ours: RMD, DEP, MLG.
3. **Confirmed detection** reads each player's `data-probabilidad` value, `Titular`/`Suplente`. The jornada comes from `span.jornada`. Real pages always contain a hidden "Alineación confirmada" span, so the heading text alone can't be trusted.
4. **Due-logic memory** is a per-team "last attempt" timestamp in the cache, not the rows. A page whose jornada differs from our next fixture, or that fails, must not be retried every 10 min when the match is far away.
5. **After kickoff**, a page for a jornada whose fixture has kicked off writes nothing, so the history stays intact. The match page shows probabilities only while the fixture is `Scheduled`. The team aside shows the half pitch only for the next jornada without a worldcup26 lineup. The manager's "Y" excludes coaches.
