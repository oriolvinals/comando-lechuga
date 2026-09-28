# API docs for the managers' AI advisor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Any manager of the league can hand `/api-docs` to an AI assistant and get sound advice on signings, bids, sales, clauses, lineups and league tracking. The AI works from fresh public data and the game's real rules, never from our private bid model.

**Architecture:**
- **Freshness stamp.** An `api`-group middleware adds `meta.generated_at` and `meta.timezone` to every JSON response.
- **Strict filters.** A validation concern turns every invalid or unknown filter on `/players` and `/activity` into a 422.
- **Two new endpoints.** A small `SeasonClock` service answers "what is happening now", served as `/api/season`. `/api/teams` reuses `LeagueStandings` and `StartProbabilities`.
- **One player shape.** An `ApiPlayerShapes` service attaches the same fields wherever a player appears: owner, figures, next fixtures with difficulty, `next_start` and value metrics.
- **Richer manager ficha.** `/api/managers/{id}` gains `current_lineup`, a lineup history of finished jornadas only, a full roster, per-jornada ranks and the daily value change. It reuses a `ManagerWeekRanks` service and a daily value concern, both extracted from the web controllers.
- **New docs.** `resources/docs/api-docs.md` is rewritten for an AI reader. A drift test checks every field the reference names against real responses from a seeded test league.

**Tech Stack:** Laravel 13 (PHP 8.5), Pest 5, Larastan level 7, Laravel Pint, SQLite (tests and local).

**Spec:** `docs/superpowers/specs/2026-09-28-api-docs-ai-design.md` (binding). Background: the audit `api-docs-audit/informe.md` and the rules summary `api-docs-audit/reglas-laliga-fantasy.md` in the session scratchpad. Only rules the spec states are used; where the two disagree, the spec wins.

## Global Constraints

- Run PHP, Artisan and Composer through Herd: `herd php artisan …`, `herd composer …`. Tests: `herd php artisan test --compact <path>`. Format: `herd php vendor/bin/pint --dirty --format agent`. Static analysis: `herd composer phpstan` (Larastan level 7) before every PHP commit.
- Every PHP file starts with `declare(strict_types=1);`. Use constructor property promotion, explicit return types, curly braces everywhere, PHPDoc array shapes and TitleCase enum keys.
- **Add no dependencies.** Create no new base folders. Services go in `app/Services/`, controller traits in `app/Http/Controllers/Concerns/`, filters in `app/Http/Filters/`, enums in `app/Enums/` and middleware in `app/Http/Middleware/`. There is no `app/Http/Requests` or `app/Rules`; validation lives in a concern.
- String columns that don't store an enum are non-nullable and default to `''`. This plan adds no column.
- **The max bid ("puja máxima rentable", god mode) is never exposed.** No API response may contain a field derived from `MaxBidCalculator` or `MaxBidEstimate`. The docs never describe it: no formula, parameters, 14-day projection or confidence. The docs never ask the AI to reproduce it. `MaxBidCalculator` does not change.
- The advisor serves **all** managers of the league. The API has no per-manager private data and **no manager cash/balance**. Do not add either.
- FútbolFantasy start probabilities are republished through the API **and always credited** (`source`, `source_url`, and the docs tell the AI to cite them).
- Inconsistent names are fixed **even if that breaks existing clients**, and the docs list every breaking change.
- Every datetime is ISO 8601 with offset. Money is always integer euros. The league timezone is `Europe/Madrid`, and the market renews daily at **20:00 Madrid time**.
- The docs are in **Spanish**, written for an AI reader, with a Spanish ↔ English glossary. Each concept has one name: "valor de mercado" / "puntos" / "posición en la liga" / "posición en el campo" are never used for each other.
- Valid fantasy formations (this league, no premium features): **3-4-3, 3-5-2, 4-3-3, 4-4-2, 4-5-1, 5-3-2, 5-4-1**, always with 1 goalkeeper.
- This league has **no captain, no bench or automatic substitutions, no coach slot and no loans (cesiones)**. The docs never present any of them as available. Coaches never appear in the API.
- `PlayerFactory` picks a random `status`. Any test whose outcome depends on status passes `'status' => PlayerStatus::Ok` (or the status under test). `PlayerFactory` stores the season figures (`position`, `market_value`, `points`…) on the season whose dates include `now()`, and creates one if none exists. Create a current-dated season **before** creating players.
- Work happens on branch `feature/api-docs-ai` with no worktree. Make one commit per task, ending with `Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`. Never merge to `main` without the user's explicit OK.

## Review Focus

- **A player with no start data, or whose team's next match is postponed:** `next_start` must be `null` ("sin datos"), never a 0 % block. Pinned in Task 4.
- **Comma-separated filters typed loosely** (`?position=midfield, striker,`): spaces and empty items are ignored and the filter works. It must not 422 or silently drop a value. Pinned in Task 2.
- **An AI appends a cache-buster to an endpoint without filters** (`/api/market?_=123`, `/api/standings?nocache=1`): the endpoint still answers 200. Only `/players` and `/activity` reject unknown parameters. Pinned in Task 2.
- **The October clock change:** `next_market_renewal_at` asked for on the Saturday evening before the change must say `20:00+01:00` on Sunday, not 19:00 or 21:00. Pinned in Task 3.
- **A jornada in progress:** a lineup player whose match is live shows his live points and no `next_start`. A teammate whose match hasn't started shows `next_start` and no points. Pinned in Task 5.

---

## File Structure

| File | Responsibility |
|---|---|
| `app/Http/Middleware/AddApiResponseMeta.php` (create) | Stamps `meta.generated_at` + `meta.timezone` on every API JSON response. |
| `bootstrap/app.php` (modify) | Prepends the middleware to the `api` group. |
| `tests/Feature/Http/Controllers/Api/ApiWorld.php` (create) | One small, complete seeded league for the API-wide tests (max-bid guard, doc drift). |
| `app/Http/Controllers/Concerns/ValidatesApiQuery.php` (create) | 422 on unknown or invalid filter parameters; comma-list rules. |
| `app/Http/Filters/ApiPlayerFilter.php` (create) | Parses `/api/players` filters (`manager`, `free`, value range, min start probability, sort). |
| `app/Enums/ApiPlayerSort.php` (create) | `/api/players` sorts, incl. `trend` and `points_per_million`. |
| `app/Enums/MarketTrend.php` (modify) | `strength()`: sort rank of a trend. |
| `app/Services/SeasonClock.php` (create) | Jornada state, first kickoff (lineup lock), buyout blackout, next fixture, next 20:00 renewal, finished weeks, lineup week. |
| `app/Http/Controllers/Api/SeasonController.php` (create) | `GET /api/season`. |
| `app/Services/ApiPlayerShapes.php` (create) | Attaches everything `PlayerResource` shows: owner, season figures, recent scores, next fixtures, `next_start`, value metrics. |
| `app/Http/Controllers/Concerns/AttachesApiNextFixtures.php` (modify) | Adds `fixture_id`, `date`, `rival_position`, `difficulty`. |
| `app/Services/ManagerWeekRanks.php` (create) | Per-jornada rank, extracted from `SeasonManagersController`. |
| `app/Http/Controllers/Concerns/AttachesDailyValueDifference.php` (create) | Squad daily value change, extracted from `HomeController`. |
| `app/Http/Controllers/Api/TeamsController.php` (create) | `GET /api/teams`. |
| `app/Http/Controllers/Api/{Players,Market,Manager,Activity}Controller.php` (modify) | Validation, renames, shared player shape, manager ficha. |
| `app/Http/Resources/{Player,PlayerDetail,Manager,Standings,Activity,FixtureLineup,Fixture}Resource.php` (modify) | New and renamed fields. |
| `app/Models/{Player,SeasonManager,MarketPlayer}.php` (modify) | Docblocks for computed properties; `MarketPlayer::SELLER_LEAGUE`. |
| `app/Http/Controllers/{SeasonManagers,Home}Controller.php` (modify) | Use the extracted service and concern. |
| `routes/api.php` (modify) | `season`, `teams`. |
| `resources/docs/api-docs.md` (rewrite) | The AI advisor guide. |
| `tests/Feature/Http/Controllers/Api/*Test.php` | Feature tests per task; `ApiDocsDriftTest.php` for the doc. |

---

### Task 1: Freshness meta, a seeded test league and the max-bid guard

**Files:**
- Create: `app/Http/Middleware/AddApiResponseMeta.php`
- Modify: `bootstrap/app.php` (imports; `withMiddleware` closure)
- Create: `tests/Feature/Http/Controllers/Api/ApiWorld.php`
- Test: `tests/Feature/Http/Controllers/Api/ResponseMetaTest.php`, `tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - `App\Http\Middleware\AddApiResponseMeta` with `public const string TIMEZONE = 'Europe/Madrid'`. Every JSON response under `api/*` (incl. 404/422) gets `meta.generated_at` (ISO 8601 in Madrid time) and `meta.timezone`, merged into any existing `meta`.
  - `Tests\Feature\Http\Controllers\Api\ApiWorld::seed(): ApiWorld` with public readonly ints `seasonId`, `managerId`, `rivalManagerId`, `ownedPlayerId`, `listedPlayerId`, `rivalPlayerId`, `finishedFixtureId`, `nextFixtureId`. In this league jornada 1 is finished, jornada 2 is current and hasn't kicked off, and jornada 3 follows. Task 9 and the max-bid guard test rely on it.

- [ ] **Step 1: Write the failing meta test**

Create `tests/Feature/Http/Controllers/Api/ResponseMetaTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Season;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 18:30:00', 'Europe/Madrid'));
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
});

test('stamps a response with when it was generated and the timezone', function (): void {
    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('meta.generated_at', '2026-09-28T18:30:00+02:00');
    $response->assertJsonPath('meta.timezone', 'Europe/Madrid');
});

test('keeps the pagination meta of a paginated response', function (): void {
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('meta.current_page', 1);
    $response->assertJsonPath('meta.per_page', 15);
    $response->assertJsonPath('meta.generated_at', '2026-09-28T18:30:00+02:00');
});

test('stamps a 404 for an unknown id too', function (): void {
    $response = $this->getJson('/api/managers/999999');

    $response->assertNotFound();
    $response->assertJsonPath('meta.timezone', 'Europe/Madrid');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/ResponseMetaTest.php`
Expected: FAIL. `meta.generated_at` is missing (the path resolves to null).

- [ ] **Step 3: Write the middleware and register it**

Create `app/Http/Middleware/AddApiResponseMeta.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stamps every JSON response of the public API with when it was generated
 * and the timezone its datetimes use. An AI advisor can then tell (and
 * quote) how fresh the data is. The stamp merges into an existing `meta`,
 * such as pagination, instead of replacing it. The middleware is prepended to
 * the `api` group so it also wraps route-model-binding 404s.
 */
class AddApiResponseMeta
{
    public const string TIMEZONE = 'Europe/Madrid';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (!$response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);

        if (!is_array($payload) || array_is_list($payload)) {
            return $response;
        }

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];

        $payload['meta'] = [
            ...$meta,
            'generated_at' => now()->setTimezone(self::TIMEZONE)->toIso8601String(),
            'timezone' => self::TIMEZONE,
        ];

        $response->setData($payload);

        return $response;
    }
}
```

In `bootstrap/app.php`, add the import next to the other middleware imports:

```php
use App\Http\Middleware\AddApiResponseMeta;
```

and make the `withMiddleware` closure start like this (the `web(append: …)` and `trustProxies` calls stay as they are):

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            AddApiResponseMeta::class,
        ]);

        $middleware->web(append: [
            HandleGodMode::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
```

- [ ] **Step 4: Run the meta test to verify it passes**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/ResponseMetaTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Create the seeded test league**

Create `tests/Feature/Http/Controllers/Api/ApiWorld.php`. PSR-4 autoloads it (`Tests\` → `tests/`), and Pest only runs `*Test.php`, so it is not collected as a test:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

/**
 * One small but complete league for the API-wide tests (the max-bid guard
 * and the doc-drift test). Every nullable object the docs describe field by
 * field is non-null in at least one response. Jornada 1 is finished,
 * jornada 2 is the current one and hasn't kicked off, and jornada 3 comes after.
 *
 * - "Comando Lechuga" owns Pedri (FC Barcelona). He was signed 9 days ago
 *   for 45 M€ and is worth 50 M€, with 41 days of market history. He
 *   started jornada 1 (9 points), is in the manager's jornada 1 and 2
 *   lineups, and has an 85 % start probability for jornada 2.
 * - "Ariobretxa" owns Tsygankov (Girona FC). Tsygankov's clause is not
 *   locked, he is shielded, and he has a 60 % start probability.
 * - Bellingham (Real Madrid) is free and listed on the league's market.
 */
final readonly class ApiWorld
{
    /** Pedri's jornada 1 breakdown: 2 + 5 − 1 + 1 + 2 = 9 points. */
    private const array OWNED_PLAYER_STATS = [
        'mins_played' => [80, 2],
        'goals' => [1, 5],
        'goal_assist' => [0, 0],
        'yellow_card' => [1, -1],
        'ball_recovery' => [5, 1],
        'marca_points' => [2, 2],
    ];

    private function __construct(
        public int $seasonId,
        public int $managerId,
        public int $rivalManagerId,
        public int $ownedPlayerId,
        public int $listedPlayerId,
        public int $rivalPlayerId,
        public int $finishedFixtureId,
        public int $nextFixtureId,
    ) {}

    public static function seed(): self
    {
        $season = Season::factory()->create([
            'start_date' => now()->subDays(60)->toDateString(),
            'end_date' => now()->addDays(200)->toDateString(),
            'total_weeks' => 38,
            'current_week' => 2,
        ]);

        $barcelona = Team::factory()->create(['main_name' => 'FC Barcelona']);
        $madrid = Team::factory()->create(['main_name' => 'Real Madrid']);
        $girona = Team::factory()->create(['main_name' => 'Girona FC']);
        $sevilla = Team::factory()->create(['main_name' => 'Sevilla FC']);
        $season->teams()->attach([$barcelona->id, $madrid->id, $girona->id, $sevilla->id]);

        $finished = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 1,
            'team_local_id' => $barcelona->id,
            'team_guest_id' => $madrid->id,
            'local_score' => 2,
            'guest_score' => 1,
            'state' => FixtureState::Finished,
            'date' => now()->subDays(8),
            'display_clock' => "90'+4'",
            'local_formation' => '4-3-3',
            'guest_formation' => '4-4-2',
            'venue' => 'Estadi Olímpic Lluís Companys',
            'venue_city' => 'Barcelona',
            'attendance' => 48_000,
            'referee' => 'Mateu Lahoz',
            'local_possession' => 58.5,
            'guest_possession' => 41.5,
        ]);
        $otherFinished = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 1,
            'team_local_id' => $girona->id,
            'team_guest_id' => $sevilla->id,
            'local_score' => 0,
            'guest_score' => 0,
            'state' => FixtureState::Finished,
            'date' => now()->subDays(8)->addHours(2),
        ]);
        $next = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 2,
            'team_local_id' => $barcelona->id,
            'team_guest_id' => $girona->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(3),
        ]);
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 2,
            'team_local_id' => $madrid->id,
            'team_guest_id' => $sevilla->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(3)->addHours(2),
        ]);
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 3,
            'team_local_id' => $sevilla->id,
            'team_guest_id' => $barcelona->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(10),
        ]);
        Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => 3,
            'team_local_id' => $girona->id,
            'team_guest_id' => $madrid->id,
            'state' => FixtureState::Scheduled,
            'date' => now()->addDays(10)->addHours(2),
        ]);

        $owned = Player::factory()->create([
            'nickname' => 'Pedri',
            'status' => PlayerStatus::Ok,
            'team_id' => $barcelona->id,
            'position' => PlayerPosition::Midfield,
            'market_value' => 50_000_000,
            'market_value_difference' => 400_000,
            'market_trend' => MarketTrend::RiseSteady,
            'points' => 20,
            'average_points' => 10.0,
        ]);
        $listed = Player::factory()->create([
            'nickname' => 'Bellingham',
            'status' => PlayerStatus::Ok,
            'team_id' => $madrid->id,
            'position' => PlayerPosition::Midfield,
            'market_value' => 60_000_000,
            'market_value_difference' => -200_000,
            'market_trend' => MarketTrend::FallSteady,
            'points' => 15,
            'average_points' => 7.5,
        ]);
        $rivalPlayer = Player::factory()->create([
            'nickname' => 'Tsygankov',
            'status' => PlayerStatus::Ok,
            'team_id' => $girona->id,
            'position' => PlayerPosition::Striker,
            'market_value' => 12_000_000,
            'market_value_difference' => 100_000,
            'market_trend' => MarketTrend::PositiveInflection,
            'points' => 8,
            'average_points' => 4.0,
        ]);

        foreach (range(40, 0) as $daysAgo) {
            PlayerMarket::factory()->create([
                'player_id' => $owned->id,
                'date' => now()->subDays($daysAgo)->toDateString(),
                'value' => 50_000_000 - $daysAgo * 250_000,
            ]);
        }

        FixtureLineup::factory()->create([
            'fixture_id' => $finished->id,
            'player_id' => $owned->id,
            'team_id' => $barcelona->id,
            'starter' => true,
            'position' => 'Center Midfielder',
            'subbed_out' => true,
            'sub_minute' => 80,
            'fantasy_points' => 9,
            'fantasy_stats' => self::OWNED_PLAYER_STATS,
        ]);
        FixtureLineup::factory()->create([
            'fixture_id' => $finished->id,
            'player_id' => $listed->id,
            'team_id' => $madrid->id,
            'starter' => true,
            'position' => 'Center Midfielder',
            'fantasy_points' => 5,
            'fantasy_stats' => ['mins_played' => [90, 2], 'goals' => [0, 0], 'marca_points' => [3, 3]],
        ]);
        FixtureLineup::factory()->create([
            'fixture_id' => $otherFinished->id,
            'player_id' => $rivalPlayer->id,
            'team_id' => $girona->id,
            'starter' => true,
            'position' => 'Forward',
            'fantasy_points' => 3,
            'fantasy_stats' => ['mins_played' => [70, 2], 'goals' => [0, 0], 'marca_points' => [1, 1]],
        ]);
        FixtureEvent::factory()->create([
            'fixture_id' => $finished->id,
            'team_id' => $barcelona->id,
            'player_id' => $owned->id,
            'type' => 'goal',
            'minute' => 30,
        ]);

        FixtureLineupProbability::factory()->onPitch(50, 40)->create([
            'fixture_id' => $next->id,
            'player_id' => $owned->id,
            'probability' => 85,
            'fetched_at' => now()->subHour(),
        ]);
        FixtureLineupProbability::factory()->create([
            'fixture_id' => $next->id,
            'player_id' => $rivalPlayer->id,
            'probability' => 60,
            'fetched_at' => now()->subHour(),
        ]);

        $manager = SeasonManager::factory()->create([
            'season_id' => $season->id,
            'name' => 'Comando Lechuga',
            'logo' => 'images/managers/1.png',
            'primary_color' => '#3d7dfd',
            'secondary_color' => '#0a0a0a',
            'position' => 1,
            'last_position' => 2,
            'total_points' => 60,
            'value' => 150_000_000,
        ]);
        $rivalManager = SeasonManager::factory()->create([
            'season_id' => $season->id,
            'name' => 'Ariobretxa',
            'logo' => 'images/managers/2.png',
            'primary_color' => '#ff0000',
            'secondary_color' => '#ffffff',
            'position' => 2,
            'last_position' => 1,
            'total_points' => 40,
            'value' => 120_000_000,
        ]);

        ManagerPlayer::factory()->create([
            'season_manager_id' => $manager->id,
            'player_id' => $owned->id,
            'buyout_clause' => 60_000_000,
            'buyout_clause_locked_until' => now()->addDays(5),
            'shielded' => false,
            'shielded_until' => null,
        ]);
        ManagerPlayer::factory()->create([
            'season_manager_id' => $rivalManager->id,
            'player_id' => $rivalPlayer->id,
            'buyout_clause' => 15_000_000,
            'buyout_clause_locked_until' => now()->subDay(),
            'shielded' => true,
            'shielded_until' => now()->addDays(2),
        ]);

        Activity::factory()->create([
            'season_id' => $season->id,
            'type' => SeasonActivityType::Signing,
            'source_season_manager_id' => $manager->id,
            'target_season_manager_id' => null,
            'player_id' => $owned->id,
            'amount' => 45_000_000,
            'week_number' => null,
            'occurred_at' => now()->subDays(9),
        ]);
        Activity::factory()->create([
            'season_id' => $season->id,
            'type' => SeasonActivityType::WeeklyPrize,
            'source_season_manager_id' => $manager->id,
            'target_season_manager_id' => null,
            'player_id' => null,
            'amount' => 6_000_000,
            'week_number' => 1,
            'occurred_at' => now()->subDays(7),
        ]);

        MarketPlayer::factory()->create([
            'player_id' => $listed->id,
            'sale_price' => 60_000_000,
            'value' => 60_000_000,
            'bids' => 2,
            'expires_at' => now()->addHours(5),
        ]);

        $managerWeek1 = ManagerLineup::factory()->create([
            'season_manager_id' => $manager->id,
            'week_number' => 1,
            'points' => 60,
            'tactical_formation' => [4, 3, 3],
        ]);
        ManagerLineupPlayer::factory()->create([
            'manager_lineup_id' => $managerWeek1->id,
            'player_id' => $owned->id,
            'fixture_id' => $finished->id,
            'position' => PlayerPosition::Midfield,
            'points' => 9,
        ]);
        $rivalWeek1 = ManagerLineup::factory()->create([
            'season_manager_id' => $rivalManager->id,
            'week_number' => 1,
            'points' => 40,
            'tactical_formation' => [4, 4, 2],
        ]);
        ManagerLineupPlayer::factory()->create([
            'manager_lineup_id' => $rivalWeek1->id,
            'player_id' => $rivalPlayer->id,
            'fixture_id' => $otherFinished->id,
            'position' => PlayerPosition::Striker,
            'points' => 3,
        ]);
        $managerWeek2 = ManagerLineup::factory()->create([
            'season_manager_id' => $manager->id,
            'week_number' => 2,
            'points' => 0,
            'tactical_formation' => [4, 3, 3],
        ]);
        ManagerLineupPlayer::factory()->create([
            'manager_lineup_id' => $managerWeek2->id,
            'player_id' => $owned->id,
            'fixture_id' => null,
            'position' => PlayerPosition::Midfield,
            'points' => null,
        ]);

        return new self(
            seasonId: $season->id,
            managerId: $manager->id,
            rivalManagerId: $rivalManager->id,
            ownedPlayerId: $owned->id,
            listedPlayerId: $listed->id,
            rivalPlayerId: $rivalPlayer->id,
            finishedFixtureId: $finished->id,
            nextFixtureId: $next->id,
        );
    }
}
```

- [ ] **Step 6: Write the max-bid guard test**

Create `tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`. The test walks every `GET api/*` route that exists, so the endpoints added later are covered automatically. A route parameter with no sample id fails loudly:

```php
<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Http\Controllers\Api\ApiWorld;

/**
 * Every key, at any depth, of a decoded JSON body.
 *
 * @return list<string>
 */
function maxBidGuardKeys(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        array_push($keys, ...maxBidGuardKeys($item));
    }

    return $keys;
}

test('no api response ever carries a field of the private max bid model', function (): void {
    $forbidden = [
        'max_bid', 'bid', 'bid_premium', 'projection', 'projected_day7', 'projected_day14',
        'momentum_increment', 'market_adjustment', 'sport_adjustment', 'daily_increment',
        'sport_score', 'rivals_effect', 'upcoming_rivals', 'confidence', 'lock_days', 'reference_date',
    ];

    $world = ApiWorld::seed();
    $sampleIds = [
        'seasonManager' => $world->managerId,
        'fixture' => $world->finishedFixtureId,
        'player' => $world->ownedPlayerId,
    ];

    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/') && in_array('GET', $route->methods(), true));

    expect($apiRoutes)->not->toBeEmpty();

    foreach ($apiRoutes as $route) {
        $url = '/'.preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $match): string => (string) ($sampleIds[$match[1]] ?? throw new RuntimeException("No sample id for route parameter {$match[1]}")),
            $route->uri(),
        );

        $response = $this->getJson($url);

        $response->assertOk();
        expect(array_values(array_intersect(maxBidGuardKeys($response->json()), $forbidden)))
            ->toBe([], "{$url} exposes a max bid field");
    }
});
```

- [ ] **Step 7: Run the guard test**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`
Expected: PASS. No endpoint exposes a max-bid field today, so this test pins the invariant for later tasks. If it fails with an error such as a factory column mismatch, the failure is in `ApiWorld`. Fix the seed and do not weaken the assertion.

- [ ] **Step 8: Run the whole API suite, format, analyse**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api`
Expected: PASS (the existing tests are unaffected; they don't assert the absence of `meta`).

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Middleware/AddApiResponseMeta.php bootstrap/app.php tests/Feature/Http/Controllers/Api/ApiWorld.php tests/Feature/Http/Controllers/Api/ResponseMetaTest.php tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php
git commit -m "feat: stamp API responses with their freshness and guard against max bid fields" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Consistent names, numeric averages and 422 on invalid filters

This task makes breaking renames. `resources/docs/api-docs.md` is only rewritten in Task 8, so it is stale until then.

**Files:**
- Create: `app/Http/Controllers/Concerns/ValidatesApiQuery.php`
- Create: `app/Http/Filters/ApiPlayerFilter.php`
- Modify: `app/Http/Controllers/Api/PlayersController.php` (imports, traits, constants, `index()`, `attachMarketListing()`)
- Modify: `app/Http/Controllers/Api/ActivityController.php` (whole file)
- Modify: `app/Http/Controllers/Api/MarketController.php` (the `$data` map)
- Modify: `app/Http/Controllers/Api/ManagerController.php` (`playerSummary()`)
- Modify: `app/Http/Resources/{Activity,Standings,Manager,FixtureLineup,Player,PlayerDetail}Resource.php`
- Modify: `app/Models/MarketPlayer.php` (constant), `app/Models/Player.php` (`api_market_listing` docblock)
- Test: `tests/Feature/Http/Controllers/Api/ApiConsistencyTest.php` (create). Modify `ActivityControllerTest.php`, `FixtureShowTest.php`, `ManagerControllerTest.php`, `MarketControllerTest.php`, `PlayerShowTest.php` and `PlayersControllerTest.php`.

**Interfaces:**
- Consumes: nothing new.
- Produces:
  - Trait `App\Http\Controllers\Concerns\ValidatesApiQuery`:
    - `private function validateApiQuery(Request $request, array<string, list<mixed>> $rules): void` throws a 422 for any query key not in `$rules` (plus `page`, a positive integer) and for any value the rules reject.
    - `private function commaSeparatedIn(list<string> $allowed): Closure` is a validation rule.
    - `private function commaSeparatedIds(): Closure` is a validation rule.
    - Both rules ignore spaces and empty items.
  - `App\Http\Filters\ApiPlayerFilter` (constructed from `Request`) with `getPositions(): PlayerPosition[]`, `getTeams(): int[]`, `getManagers(): int[]`, `getStatuses(): PlayerStatus[]`, `getSearch(): ?string`, `getSort(): PlayerSort` and `getDirection(): SortDirection`. Task 4 replaces this file.
  - `App\Models\MarketPlayer::SELLER_LEAGUE = 'league'`.
  - Renamed response fields:
    - Activity: `source_manager`, `target_manager`.
    - Standings and manager: `rank`, `last_rank`, `squad_value`.
    - Market listing: `market_value`, `seller`.
    - Fixture lineup: `pitch_position`.
    - Players filter: `manager`.
    - `average_points` is a float.

- [ ] **Step 1: Write the failing consistency tests**

Create `tests/Feature/Http/Controllers/Api/ApiConsistencyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;

function consistencySeason(): Season
{
    return Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
}

test('names the standings rank, previous rank and squad value unambiguously', function (): void {
    $season = consistencySeason();
    SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1, 'last_position' => 3, 'value' => 150_000_000]);

    $response = $this->getJson('/api/standings');

    $response->assertOk();
    $response->assertJsonPath('data.0.rank', 1);
    $response->assertJsonPath('data.0.last_rank', 3);
    $response->assertJsonPath('data.0.squad_value', 150_000_000);
    $response->assertJsonMissingPath('data.0.position');
    $response->assertJsonMissingPath('data.0.value');
});

test('names the managers of an activity source_manager and target_manager', function (): void {
    $season = consistencySeason();
    $buyer = SeasonManager::factory()->create(['season_id' => $season->id]);
    $seller = SeasonManager::factory()->create(['season_id' => $season->id]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Buyout,
        'source_season_manager_id' => $buyer->id,
        'target_season_manager_id' => $seller->id,
    ]);

    $response = $this->getJson('/api/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.source_manager.id', $buyer->id);
    $response->assertJsonPath('data.0.target_manager.id', $seller->id);
    $response->assertJsonMissingPath('data.0.source_season_manager');
});

test('names a market listing\'s current value market_value and says the league sells it', function (): void {
    consistencySeason();
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create(['position' => PlayerPosition::Midfield])->id,
        'value' => 4_800_000,
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->getJson('/api/market');

    $response->assertOk();
    $response->assertJsonPath('data.0.market_value', 4_800_000);
    $response->assertJsonPath('data.0.seller', 'league');
    $response->assertJsonMissingPath('data.0.value');
});

test('names a fixture lineup\'s raw tactical slot pitch_position', function (): void {
    $season = consistencySeason();
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'position' => 'Center Midfielder']);

    $response = $this->getJson("/api/fixtures/{$fixture->id}");

    $response->assertOk();
    $response->assertJsonPath('data.lineups.0.pitch_position', 'Center Midfielder');
    $response->assertJsonMissingPath('data.lineups.0.position');
});

test('returns average_points as a number', function (): void {
    consistencySeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'average_points' => 6.5]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.average_points', 6.5);
});

test('filters players by owner with the manager parameter', function (): void {
    $season = consistencySeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $owned->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->getJson("/api/players?manager={$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $owned->id);
});

test('rejects an invalid players filter with a 422 naming the parameter', function (string $query, string $parameter): void {
    consistencySeason();

    $response = $this->getJson("/api/players?{$query}");

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors([$parameter]);
})->with([
    'unknown sort' => ['sort=bogus', 'sort'],
    'unknown direction' => ['direction=up', 'direction'],
    'coach position' => ['position=coach', 'position'],
    'out of league status' => ['status=out_of_league', 'status'],
    'non numeric team' => ['team=barcelona', 'team'],
    'renamed owner filter' => ['season_manager=4', 'season_manager'],
    'bad page' => ['page=0', 'page'],
]);

test('rejects an invalid activity type with a 422', function (): void {
    consistencySeason();

    $response = $this->getJson('/api/activity?type=signing,bogus');

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['type']);
});

test('tolerates spaces and empty items in a comma separated filter', function (): void {
    consistencySeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Midfield]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Striker]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Goalkeeper]);

    $response = $this->getJson('/api/players?position=midfield,%20striker,');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

test('ignores the query string on endpoints without filters', function (): void {
    consistencySeason();

    $this->getJson('/api/market?_=123')->assertOk();
    $this->getJson('/api/standings?nocache=1')->assertOk();
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/ApiConsistencyTest.php`
Expected: FAIL. The renamed paths are missing, `average_points` is `"6.50"`, and the invalid filters return 200.

- [ ] **Step 3: Write the validation concern**

Create `app/Http/Controllers/Concerns/ValidatesApiQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

trait ValidatesApiQuery
{
    /**
     * Fails with a 422 naming the parameter when the query string has a
     * parameter this endpoint doesn't know, or a value its rules reject.
     * The public API never silently ignores a filter, so an AI advisor
     * learns its request was wrong instead of reading unfiltered data.
     * `page` is always allowed (a positive integer). Empty values count as
     * absent (ConvertEmptyStringsToNull + `nullable`).
     *
     * @param  array<string, list<mixed>>  $rules
     *
     * @throws ValidationException
     */
    private function validateApiQuery(Request $request, array $rules): void
    {
        $rules['page'] = ['sometimes', 'nullable', 'integer', 'min:1'];
        $query = $request->query->all();

        $unknown = array_values(array_diff(array_map(strval(...), array_keys($query)), array_keys($rules)));

        if ($unknown !== []) {
            $messages = [];

            foreach ($unknown as $parameter) {
                $messages[$parameter] = ["Parámetro desconocido: {$parameter}. Parámetros válidos: ".implode(', ', array_keys($rules)).'.'];
            }

            throw ValidationException::withMessages($messages);
        }

        Validator::make($query, $rules)->validate();
    }

    /**
     * A comma-separated list whose every item is one of `$allowed`. Spaces
     * and empty items are ignored.
     *
     * @param  list<string>  $allowed
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function commaSeparatedIn(array $allowed): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($allowed): void {
            $invalid = array_values(array_diff($this->commaSeparatedItems($value), $allowed));

            if (!is_string($value) || $invalid !== []) {
                $fail("{$attribute}: valor no válido (".implode(', ', $invalid).'). Valores permitidos: '.implode(', ', $allowed).'.');
            }
        };
    }

    /**
     * A comma-separated list of positive integer ids. Spaces and empty items
     * are ignored.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    private function commaSeparatedIds(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $invalid = array_values(array_filter(
                $this->commaSeparatedItems($value),
                fn (string $item): bool => !ctype_digit($item) || (int) $item === 0,
            ));

            if (!is_string($value) || $invalid !== []) {
                $fail("{$attribute}: se esperaban IDs numéricos separados por comas (no válidos: ".implode(', ', $invalid).').');
            }
        };
    }

    /**
     * @return list<string>
     */
    private function commaSeparatedItems(mixed $value): array
    {
        if (!is_string($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode(',', $value)),
            fn (string $item): bool => $item !== '',
        ));
    }
}
```

- [ ] **Step 4: Write the API player filter**

Create `app/Http/Filters/ApiPlayerFilter.php`. The web's `PlayerFilter` keeps `season_manager`; the API uses `manager`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Filters;

use App\Enums\PlayerPosition;
use App\Enums\PlayerSort;
use App\Enums\PlayerStatus;
use App\Enums\SortDirection;
use Illuminate\Http\Request;

/**
 * `/api/players` filters. Validation (422) happens in the controller; this
 * only parses already-valid input.
 */
final class ApiPlayerFilter extends BaseRequestFilter
{
    /** @var PlayerPosition[] */
    private readonly array $positions;

    /** @var int[] */
    private readonly array $teams;

    /** @var int[] */
    private readonly array $managers;

    /** @var PlayerStatus[] */
    private readonly array $statuses;

    private readonly ?string $search;

    private readonly PlayerSort $sort;

    private readonly SortDirection $direction;

    public function __construct(Request $request)
    {
        $this->positions = $this->parseEnumList(PlayerPosition::class, $request->string('position')->toString());
        $this->teams = $this->parseIntList($request->string('team')->toString());
        $this->managers = $this->parseIntList($request->string('manager')->toString());
        $this->statuses = $this->parseEnumList(PlayerStatus::class, $request->string('status')->toString());
        $this->search = $this->parseString($request->string('search')->toString());
        $this->sort = $this->parseEnum(PlayerSort::class, $request->string('sort')->toString()) ?? PlayerSort::Points;
        $this->direction = $this->parseEnum(SortDirection::class, $request->string('direction')->toString()) ?? SortDirection::Desc;
    }

    /**
     * @return PlayerPosition[]
     */
    public function getPositions(): array
    {
        return $this->positions;
    }

    /**
     * @return int[]
     */
    public function getTeams(): array
    {
        return $this->teams;
    }

    /**
     * @return int[]
     */
    public function getManagers(): array
    {
        return $this->managers;
    }

    /**
     * @return PlayerStatus[]
     */
    public function getStatuses(): array
    {
        return $this->statuses;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    public function getSort(): PlayerSort
    {
        return $this->sort;
    }

    public function getDirection(): SortDirection
    {
        return $this->direction;
    }
}
```

- [ ] **Step 5: Validate and rename in the players controller**

In `app/Http/Controllers/Api/PlayersController.php`:

Replace `use App\Http\Filters\PlayerFilter;` with these imports. Keep the other imports and keep them alphabetical:

```php
use App\Enums\PlayerSort;
use App\Enums\SortDirection;
use App\Http\Controllers\Concerns\ValidatesApiQuery;
use App\Http\Filters\ApiPlayerFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
```

Add `use ValidatesApiQuery;` after `use AttachesOwnerManager;` inside the class. Add these constants after `ACCENT_FOLD`:

```php
    /** Positions the API can filter by. Coaches are never in the API. */
    private const array FILTERABLE_POSITIONS = ['goalkeeper', 'defender', 'midfield', 'striker'];

    /** Statuses the list can filter by. Out-of-league players are never listed. */
    private const array FILTERABLE_STATUSES = ['ok', 'injured', 'doubtful', 'suspended'];
```

Replace the whole `index()` method with:

```php
    public function index(Request $request, ApiPlayerFilter $filter): AnonymousResourceCollection
    {
        $this->validateApiQuery($request, [
            'position' => ['sometimes', 'nullable', $this->commaSeparatedIn(self::FILTERABLE_POSITIONS)],
            'team' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'manager' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'status' => ['sometimes', 'nullable', $this->commaSeparatedIn(self::FILTERABLE_STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'sort' => ['sometimes', 'nullable', Rule::enum(PlayerSort::class)],
            'direction' => ['sometimes', 'nullable', Rule::enum(SortDirection::class)],
        ]);

        $season = Season::current();

        $positions = $filter->getPositions();
        $teams = $filter->getTeams();
        $managers = $filter->getManagers();
        $statuses = $filter->getStatuses();
        $search = $filter->getSearch();

        $players = Player::query()
            ->select('players.*')
            ->join('player_seasons', function ($join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'players.id')
                    ->where('player_seasons.season_id', $season->id);
            })
            ->with('team')
            ->whereNotNull('fantasy_id')
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->when($positions !== [], fn ($query) => $query->whereIn('player_seasons.position', $positions))
            ->when($teams !== [], fn ($query) => $query->whereIn('team_id', $teams))
            ->when($managers !== [], fn ($query) => $query->whereHas(
                'seasonManagerPlayers',
                fn ($query) => $query->whereIn('season_manager_id', $managers),
            ))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($search !== null, fn ($query) => $query->whereRaw(
                $this->foldedNicknameSql().' LIKE ?',
                ['%'.Str::lower(Str::ascii($search)).'%'],
            ))
            ->orderBy('player_seasons.'.$filter->getSort()->column(), $filter->getDirection()->value)
            ->paginate(15)
            ->withQueryString();

        $this->attachOwnerManager($players->getCollection(), $season->id);
        $this->attachCurrentSeason($players->getCollection(), $season->id);
        $this->attachApiRecentScores($players->getCollection(), $season);
        $this->attachApiNextFixtures($players->getCollection(), $season);

        return PlayerResource::collection($players);
    }
```

Replace `attachMarketListing()` with:

```php
    private function attachMarketListing(Player $player): void
    {
        $listing = MarketPlayer::query()->where('player_id', $player->id)->first();

        $player->api_market_listing = $listing === null ? null : [
            'sale_price' => $listing->sale_price,
            'market_value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
            'seller' => MarketPlayer::SELLER_LEAGUE,
        ];
    }
```

- [ ] **Step 6: Validate the activity filters**

Replace `app/Http/Controllers/Api/ActivityController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\ValidatesApiQuery;
use App\Http\Controllers\Controller;
use App\Http\Filters\ActivityFilter;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    use AttachesActivityValueDifference;
    use ValidatesApiQuery;

    public function index(Request $request, ActivityFilter $filter): AnonymousResourceCollection
    {
        $this->validateApiQuery($request, [
            'manager' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'player' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'type' => ['sometimes', 'nullable', $this->commaSeparatedIn(array_map(
                fn (SeasonActivityType $type): string => $type->value,
                SeasonActivityType::cases(),
            ))],
        ]);

        $season = Season::current();

        $managerIds = $filter->getManagers();
        $types = $filter->getTypes();
        $playerIds = $filter->getPlayers();

        $activities = Activity::query()
            ->where('season_id', $season->id)
            ->when($managerIds !== [], fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereIn('source_season_manager_id', $managerIds)
                    ->orWhereIn('target_season_manager_id', $managerIds),
            ))
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->when($playerIds !== [], fn ($query) => $query->whereIn('player_id', $playerIds))
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString();

        $this->attachValueDifferences($activities->getCollection());

        return ActivityResource::collection($activities);
    }
}
```

- [ ] **Step 7: Rename the resource fields**

`app/Models/MarketPlayer.php`: add inside the class, before the first method:

```php
    /**
     * Who sells every listing we sync: the league itself (the provider's
     * `marketPlayerLeague`). Players that managers list are not synced.
     */
    public const string SELLER_LEAGUE = 'league';
```

`app/Models/Player.php`: replace the `api_market_listing` docblock line with:

```php
 * @property array{sale_price: int, market_value: int, bids: int, expires_at: string, seller: string}|null $api_market_listing The player's current market listing, if any. Computed at query time by Api\PlayersController; not a database column.
```

`app/Http/Controllers/Api/MarketController.php`: replace the `$data = …` map with:

```php
        $data = $listings->map(fn (MarketPlayer $listing): array => [
            'player' => (new PlayerResource($listing->player))->resolve(),
            'sale_price' => $listing->sale_price,
            'market_value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
            'seller' => MarketPlayer::SELLER_LEAGUE,
        ]);
```

`app/Http/Resources/ActivityResource.php`: in `toArray()`, rename the two keys and keep their values:

```php
            'source_manager' => [
                'id' => $this->sourceSeasonManager->id,
                'name' => $this->sourceSeasonManager->name,
            ],
            'target_manager' => $this->targetSeasonManager === null ? null : [
                'id' => $this->targetSeasonManager->id,
                'name' => $this->targetSeasonManager->name,
            ],
```

`app/Http/Resources/StandingsResource.php`: replace the `position`, `last_position` and `value` entries with:

```php
            'rank' => $this->position,
            'last_rank' => $this->last_position,
            'total_points' => $this->total_points,
            'squad_value' => $this->value,
```

`app/Http/Resources/ManagerResource.php`: replace the `position`, `last_position` and `value` entries the same way:

```php
            'rank' => $this->position,
            'last_rank' => $this->last_position,
            'total_points' => $this->total_points,
            'squad_value' => $this->value,
```

`app/Http/Resources/FixtureLineupResource.php`: replace `'position' => $this->position,` with:

```php
            'pitch_position' => $this->position,
```

`app/Http/Resources/PlayerResource.php` and `app/Http/Resources/PlayerDetailResource.php`: replace `'average_points' => $this->average_points,` with:

```php
            'average_points' => (float) $this->average_points,
```

`app/Http/Controllers/Api/ManagerController.php`, in `playerSummary()`: replace `'average_points' => $player->average_points,` with:

```php
            'average_points' => (float) $player->average_points,
```

- [ ] **Step 8: Update the existing tests to the new names**

- `tests/Feature/Http/Controllers/Api/ActivityControllerTest.php`:
  - Replace `'data.0.source_season_manager'` with `'data.0.source_manager'` (line 63).
  - Replace `'data.0.target_season_manager'` with `'data.0.target_manager'` (lines 64 and 86).
  - Rename the test title on line 69 to `'has a null target_manager and player when the activity has none'`.
- `tests/Feature/Http/Controllers/Api/FixtureShowTest.php` line 77: `'data.lineups.0.position'` → `'data.lineups.0.pitch_position'`.
- `tests/Feature/Http/Controllers/Api/ManagerControllerTest.php` lines 34–37:
  - `'data.position'` → `'data.rank'`
  - `'data.last_position'` → `'data.last_rank'`
  - `'data.value'` → `'data.squad_value'`
- `tests/Feature/Http/Controllers/Api/MarketControllerTest.php` line 40: `'data.0.value'` → `'data.0.market_value'`.
- `tests/Feature/Http/Controllers/Api/PlayerShowTest.php` line 101: `'data.market_listing.value'` → `'data.market_listing.market_value'`.
- `tests/Feature/Http/Controllers/Api/PlayersControllerTest.php` line 85: `"/api/players?season_manager={$manager->id}"` → `"/api/players?manager={$manager->id}"`.

- [ ] **Step 9: Run the API suite**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api`
Expected: PASS, including `ApiConsistencyTest` and `MaxBidGuardTest`.

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/PlayersControllerTest.php tests/Feature/Http/Controllers/ActivityControllerTest.php`
Expected: PASS. The web pages still use `PlayerFilter`/`ActivityFilter` unchanged.

- [ ] **Step 10: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Http/Controllers/Concerns/ValidatesApiQuery.php app/Http/Filters/ApiPlayerFilter.php app/Http/Controllers/Api app/Http/Resources app/Models/MarketPlayer.php app/Models/Player.php tests/Feature/Http/Controllers/Api
git commit -m "feat!: name API fields consistently and reject invalid filters with a 422" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---
### Task 3: `GET /api/season`: what is happening now

**Files:**
- Create: `app/Services/SeasonClock.php`
- Create: `app/Http/Controllers/Api/SeasonController.php`
- Modify: `routes/api.php`
- Modify: `app/Http/Resources/FixtureResource.php` (adds `week_number`)
- Test: `tests/Feature/Http/Controllers/Api/SeasonControllerTest.php`

**Interfaces:**
- Consumes: `FixtureResource`.
- Produces:
  - `App\Services\SeasonClock`:
    - Constants: `TIMEZONE = 'Europe/Madrid'`, `MARKET_RENEWAL_HOUR = 20`, `BUYOUT_BLACKOUT_HOURS = 24`, `NOT_STARTED = 'not_started'`, `LIVE = 'live'`, `FINISHED = 'finished'`.
    - `weekState(Season $season, int $weekNumber): 'not_started'|'live'|'finished'` ignores postponed fixtures.
    - `firstKickoff(Season $season, int $weekNumber): ?CarbonImmutable` returns the earliest non-postponed fixture date.
    - `upcomingWeek(Season $season): ?array{week_number: int, lineup_locks_at: CarbonImmutable, buyouts_close_at: CarbonImmutable}` (phpstan type `UpcomingWeek`).
    - `buyoutsOpen(?array $upcomingWeek, CarbonImmutable $now): bool`.
    - `nextFixture(Season $season, CarbonImmutable $now): ?Fixture` (teams loaded).
    - `nextMarketRenewal(CarbonImmutable $now): CarbonImmutable`.
    - Task 5 adds `finishedWeekNumbers()` and `lineupWeek()`.
  - Route `api.season` → `GET /api/season`.
  - `FixtureResource` gains `week_number` (so `/api/fixtures` entries and `next_fixture` carry it).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Controllers/Api/SeasonControllerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00', 'Europe/Madrid'));
});

function clockSeason(int $currentWeek, int $totalWeeks = 38): Season
{
    return Season::factory()->create([
        'name' => 'LaLiga 26/27',
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(200),
        'current_week' => $currentWeek,
        'total_weeks' => $totalWeeks,
    ]);
}

function clockFixture(Season $season, int $weekNumber, FixtureState $state, CarbonInterface $date): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => $weekNumber,
        'state' => $state,
        'date' => $date,
    ]);
}

test('describes a jornada that has not kicked off yet, ignoring a postponed match', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 6, FixtureState::Finished, now()->subDays(6));
    clockFixture($season, 7, FixtureState::Postponed, now()->addDay()->setTime(18, 0));
    $lock = now()->addDays(2)->setTime(18, 30);
    $first = clockFixture($season, 7, FixtureState::Scheduled, $lock);
    clockFixture($season, 7, FixtureState::Scheduled, now()->addDays(3)->setTime(21, 0));

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.name', 'LaLiga 26/27');
    $response->assertJsonPath('data.total_weeks', 38);
    $response->assertJsonPath('data.current_week', 7);
    $response->assertJsonPath('data.current_week_state', 'not_started');
    $response->assertJsonPath('data.upcoming_week.week_number', 7);
    $response->assertJsonPath('data.upcoming_week.lineup_locks_at', $lock->toIso8601String());
    $response->assertJsonPath('data.upcoming_week.buyouts_close_at', $lock->subHours(24)->toIso8601String());
    $response->assertJsonPath('data.upcoming_week.buyouts_reopen_at', $lock->toIso8601String());
    $response->assertJsonPath('data.buyouts_open', true);
    $response->assertJsonPath('data.next_fixture.id', $first->id);
    $response->assertJsonPath('data.next_fixture.week_number', 7);
});

test('closes buyouts in the 24 hours before the jornada\'s first kickoff', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 7, FixtureState::Scheduled, now()->addHours(10));

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.buyouts_open', false);
});

test('moves the upcoming jornada on once the current one is live', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 7, FixtureState::FirstHalf, now()->subMinutes(20));
    clockFixture($season, 7, FixtureState::Scheduled, now()->addDay());
    $nextLock = now()->addDays(5)->setTime(16, 15);
    clockFixture($season, 8, FixtureState::Scheduled, $nextLock);

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.current_week_state', 'live');
    $response->assertJsonPath('data.upcoming_week.week_number', 8);
    $response->assertJsonPath('data.upcoming_week.lineup_locks_at', $nextLock->toIso8601String());
});

test('marks a jornada finished when every match but a postponed one has finished', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 7, FixtureState::Finished, now()->subDay());
    clockFixture($season, 7, FixtureState::Postponed, now()->subDays(2));
    clockFixture($season, 8, FixtureState::Scheduled, now()->addDays(4));

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.current_week_state', 'finished');
    $response->assertJsonPath('data.upcoming_week.week_number', 8);
});

test('has no upcoming jornada, next match or buyout window after the last jornada', function (): void {
    $season = clockSeason(38);
    clockFixture($season, 38, FixtureState::Finished, now()->subDay());

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.current_week_state', 'finished');
    $response->assertJsonPath('data.upcoming_week', null);
    $response->assertJsonPath('data.next_fixture', null);
    $response->assertJsonPath('data.buyouts_open', true);
});

test('renews the market at the next 20:00 in Madrid', function (): void {
    clockSeason(7);

    $this->getJson('/api/season')->assertJsonPath('data.next_market_renewal_at', '2026-09-28T20:00:00+02:00');

    $this->travelTo(CarbonImmutable::parse('2026-09-28 20:00:00', 'Europe/Madrid'));

    $this->getJson('/api/season')->assertJsonPath('data.next_market_renewal_at', '2026-09-29T20:00:00+02:00');
});

test('keeps the market renewal at 20:00 across the October clock change', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 21:00:00', 'Europe/Madrid'));
    clockSeason(7);

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.next_market_renewal_at', '2026-10-25T20:00:00+01:00');
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/SeasonControllerTest.php`
Expected: FAIL with 404 (no `api/season` route).

- [ ] **Step 3: Write the clock service**

Create `app/Services/SeasonClock.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use Carbon\CarbonImmutable;

/**
 * The public API's sense of "now" for a season. It answers:
 * - the state of a jornada;
 * - the next jornada whose lineup still has to be saved (it locks at the
 *   jornada's first kickoff, and buyouts close 24 h before that);
 * - the next match;
 * - the next daily market renewal (20:00 in Madrid, the time this league
 *   was created).
 *
 * Postponed fixtures never count. A postponed match keeps its jornada, but
 * neither the jornada's state nor its first kickoff looks at it.
 *
 * @phpstan-type UpcomingWeek array{week_number: int, lineup_locks_at: CarbonImmutable, buyouts_close_at: CarbonImmutable}
 */
class SeasonClock
{
    public const string TIMEZONE = 'Europe/Madrid';

    public const int MARKET_RENEWAL_HOUR = 20;

    public const int BUYOUT_BLACKOUT_HOURS = 24;

    public const string NOT_STARTED = 'not_started';

    public const string LIVE = 'live';

    public const string FINISHED = 'finished';

    /**
     * 'not_started' until one of the jornada's non-postponed matches kicks
     * off, 'finished' once all of them have finished, 'live' in between.
     *
     * @return 'not_started'|'live'|'finished'
     */
    public function weekState(Season $season, int $weekNumber): string
    {
        $states = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $weekNumber)
            ->where('state', '!=', FixtureState::Postponed)
            ->get(['state'])
            ->map(fn (Fixture $fixture): FixtureState => $fixture->state);

        if ($states->every(fn (FixtureState $state): bool => $state === FixtureState::Scheduled)) {
            return self::NOT_STARTED;
        }

        return $states->every(fn (FixtureState $state): bool => $state === FixtureState::Finished)
            ? self::FINISHED
            : self::LIVE;
    }

    /**
     * The jornada's earliest kickoff among its non-postponed matches: its
     * lineup lock. Null when the jornada has no such match.
     */
    public function firstKickoff(Season $season, int $weekNumber): ?CarbonImmutable
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $weekNumber)
            ->where('state', '!=', FixtureState::Postponed)
            ->orderBy('date')
            ->first(['date'])
            ?->date;
    }

    /**
     * The next jornada whose lineup can still be saved: the current one
     * while it hasn't kicked off, else the following one. Null after the
     * last jornada, or when that jornada has no match on the calendar.
     *
     * @return UpcomingWeek|null
     */
    public function upcomingWeek(Season $season): ?array
    {
        $weekNumber = $this->weekState($season, $season->current_week) === self::NOT_STARTED
            ? $season->current_week
            : $season->current_week + 1;

        if ($weekNumber > $season->total_weeks) {
            return null;
        }

        $lineupLocksAt = $this->firstKickoff($season, $weekNumber);

        if ($lineupLocksAt === null) {
            return null;
        }

        return [
            'week_number' => $weekNumber,
            'lineup_locks_at' => $lineupLocksAt,
            'buyouts_close_at' => $lineupLocksAt->subHours(self::BUYOUT_BLACKOUT_HOURS),
        ];
    }

    /**
     * Buyouts are closed from 24 h before a jornada's first kickoff until
     * that kickoff; open at any other time.
     *
     * @param  UpcomingWeek|null  $upcomingWeek
     */
    public function buyoutsOpen(?array $upcomingWeek, CarbonImmutable $now): bool
    {
        if ($upcomingWeek === null) {
            return true;
        }

        return $now->lessThan($upcomingWeek['buyouts_close_at'])
            || $now->greaterThanOrEqualTo($upcomingWeek['lineup_locks_at']);
    }

    /**
     * The first scheduled match of any jornada that hasn't kicked off yet.
     */
    public function nextFixture(Season $season, CarbonImmutable $now): ?Fixture
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', $now)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->first();
    }

    /**
     * The next 20:00 in Madrid strictly after `$now`. It is computed on the
     * Madrid wall clock, so a clock change never moves it to 19:00 or 21:00.
     */
    public function nextMarketRenewal(CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone(self::TIMEZONE);
        $renewal = $local->setTime(self::MARKET_RENEWAL_HOUR, 0);

        return $renewal->greaterThan($local) ? $renewal : $renewal->addDay();
    }
}
```

- [ ] **Step 4: Write the controller, route and `week_number` on fixtures**

Create `app/Http/Controllers/Api/SeasonController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FixtureResource;
use App\Models\Season;
use App\Services\SeasonClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class SeasonController extends Controller
{
    public function show(SeasonClock $clock): JsonResponse
    {
        $season = Season::current();
        $now = CarbonImmutable::now();
        $upcomingWeek = $clock->upcomingWeek($season);
        $nextFixture = $clock->nextFixture($season, $now);

        return response()->json(['data' => [
            'name' => $season->name,
            'start_date' => $season->start_date->toDateString(),
            'end_date' => $season->end_date->toDateString(),
            'total_weeks' => $season->total_weeks,
            'current_week' => $season->current_week,
            'current_week_state' => $clock->weekState($season, $season->current_week),
            'upcoming_week' => $upcomingWeek === null ? null : [
                'week_number' => $upcomingWeek['week_number'],
                'lineup_locks_at' => $upcomingWeek['lineup_locks_at']->toIso8601String(),
                'buyouts_close_at' => $upcomingWeek['buyouts_close_at']->toIso8601String(),
                'buyouts_reopen_at' => $upcomingWeek['lineup_locks_at']->toIso8601String(),
            ],
            'buyouts_open' => $clock->buyoutsOpen($upcomingWeek, $now),
            'next_fixture' => $nextFixture === null ? null : (new FixtureResource($nextFixture))->resolve(),
            'next_market_renewal_at' => $clock->nextMarketRenewal($now)->toIso8601String(),
        ]]);
    }
}
```

In `routes/api.php`, add `use App\Http\Controllers\Api\SeasonController;` to the imports (alphabetical) and this route as the first route:

```php
Route::get('season', [SeasonController::class, 'show'])->name('api.season');
```

In `app/Http/Resources/FixtureResource.php`, add `week_number` right after `url`:

```php
            'url' => route('api.fixtures.show', $this->id),
            'week_number' => $this->week_number,
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/SeasonControllerTest.php tests/Feature/Http/Controllers/Api/FixturesControllerTest.php tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`
Expected: PASS. The guard test now also calls `/api/season`.

- [ ] **Step 6: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Services/SeasonClock.php app/Http/Controllers/Api/SeasonController.php routes/api.php app/Http/Resources/FixtureResource.php tests/Feature/Http/Controllers/Api/SeasonControllerTest.php
git commit -m "feat: add GET /api/season with the jornada state, lineup lock, buyout window and market renewal" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 4: One player shape everywhere (next start, rival difficulty, value metrics) and the new `/players` sorts and filters

**Files:**
- Create: `app/Services/ApiPlayerShapes.php`
- Create: `app/Enums/ApiPlayerSort.php`
- Modify: `app/Enums/MarketTrend.php` (adds `strength()`)
- Modify: `app/Http/Controllers/Concerns/AttachesApiNextFixtures.php` (whole file)
- Modify: `app/Http/Filters/ApiPlayerFilter.php` (whole file)
- Modify: `app/Http/Controllers/Api/PlayersController.php` (whole file)
- Modify: `app/Http/Controllers/Api/MarketController.php` (whole file)
- Modify: `app/Http/Resources/PlayerResource.php`, `app/Http/Resources/PlayerDetailResource.php` (whole `toArray()`)
- Modify: `app/Models/Player.php` (docblock)
- Test: `tests/Feature/Http/Controllers/Api/PlayerSignalsTest.php`

**Interfaces:**
- Consumes:
  - `StartProbabilities::forPlayersNextFixture(Collection<int, Player>, Season): array<int, PlayerNextStart>` (existing).
  - `PlayerMarketMetrics::valueTrend(int, Collection<int, PlayerMarket>)`, `pointsPerMillion(Player, Season)` and `capitalGain(int, ?ManagerPlayer, Collection<int, Activity>)` (existing).
  - `LeagueStandings::positions(Season): array<int, int>` and `LeagueStandings::difficulty(int, int): float` (existing).
  - `ValidatesApiQuery` (Task 2).
- Produces:
  - `App\Services\ApiPlayerShapes`:
    - `attach(Collection<int, Player> $players, Season $season): void`. The players need `team` loaded.
    - `public static presentNextStart(array $start): array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, source: string, is_stale: bool, fetched_at: string|null, source_url: string}`. Tasks 5 and 7 use it.
  - `PlayerResource`/`PlayerDetailResource` gain `next_start`, `value_trend_30d`, `points_per_million` and `owner_gain`.
  - `next_fixtures[]` gains `fixture_id`, `date`, `rival_position` and `difficulty`.
  - `App\Enums\ApiPlayerSort` has cases `Points='points'`, `Value='value'`, `Difference='difference'`, `Trend='trend'` and `PointsPerMillion='points_per_million'`.
  - `MarketTrend::strength(): int` ranges from +6 (rise, sharply accelerating) to −6 (fall, sharply accelerating).
  - `/api/players` query parameters: `free`, `min_value`, `max_value`, `min_start_probability`, `sort=trend|points_per_million`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Controllers/Api/PlayerSignalsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

function signalsSeason(): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDays(60),
        'end_date' => now()->addDays(200),
        'current_week' => 2,
    ]);
}

function signalsNextFixture(Season $season, Team $local, Team $guest, FixtureState $state = FixtureState::Scheduled): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'state' => $state,
        'team_local_id' => $local->id,
        'team_guest_id' => $guest->id,
        'date' => now()->addDays(2),
    ]);
}

test('adds the next start from FútbolFantasy to every player in the list', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $rival = Team::factory()->create(['main_name' => 'Rival FC']);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $fixture = signalsNextFixture($season, $rival, $team);
    FixtureLineupProbability::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'probability' => 85,
        'predicted_starter' => true,
        'fetched_at' => now()->subHour(),
    ]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start.fixture_id', $fixture->id);
    $response->assertJsonPath('data.0.next_start.week_number', 2);
    $response->assertJsonPath('data.0.next_start.date', $fixture->date->toIso8601String());
    $response->assertJsonPath('data.0.next_start.opponent.name', 'Rival FC');
    $response->assertJsonPath('data.0.next_start.is_home', false);
    $response->assertJsonPath('data.0.next_start.probability', 85);
    $response->assertJsonPath('data.0.next_start.predicted_starter', true);
    $response->assertJsonPath('data.0.next_start.confirmed_starter', null);
    $response->assertJsonPath('data.0.next_start.source', 'futbolfantasy');
    $response->assertJsonPath('data.0.next_start.is_stale', false);
});

test('has a null next start, never a zero, when there is no data', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    signalsNextFixture($season, $team, Team::factory()->create());

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start', null);
});

test('has a null next start when the team\'s next match is postponed', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $postponed = signalsNextFixture($season, $team, Team::factory()->create(), FixtureState::Postponed);
    FixtureLineupProbability::factory()->create(['fixture_id' => $postponed->id, 'player_id' => $player->id, 'probability' => 90]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start', null);
});

test('says the worldcup26 lineup confirmed the start once it has', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $fixture = signalsNextFixture($season, $team, Team::factory()->create());
    FixtureLineupProbability::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'probability' => 40]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $team->id, 'starter' => true]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start.source', 'worldcup26');
    $response->assertJsonPath('data.0.next_start.confirmed_starter', true);
});

test('adds the next start to market listings and to the player ficha', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id, 'position' => PlayerPosition::Striker]);
    $fixture = signalsNextFixture($season, $team, Team::factory()->create());
    FixtureLineupProbability::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'probability' => 70]);
    MarketPlayer::factory()->create(['player_id' => $player->id, 'expires_at' => now()->addHours(3)]);

    $this->getJson('/api/market')->assertJsonPath('data.0.player.next_start.probability', 70);
    $this->getJson("/api/players/{$player->id}")->assertJsonPath('data.next_start.probability', 70);
});

test('rates each next fixture by the rival\'s real standings position', function (): void {
    $season = signalsSeason();
    $alpha = Team::factory()->create(['main_name' => 'Alpha FC']);
    $bravo = Team::factory()->create(['main_name' => 'Bravo FC']);
    $charlie = Team::factory()->create(['main_name' => 'Charlie FC']);
    $delta = Team::factory()->create(['main_name' => 'Delta FC']);
    $season->teams()->attach([$alpha->id, $bravo->id, $charlie->id, $delta->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $alpha->id]);
    $fixture = signalsNextFixture($season, $alpha, $delta);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_fixtures.0.fixture_id', $fixture->id);
    $response->assertJsonPath('data.0.next_fixtures.0.date', $fixture->date->toIso8601String());
    $response->assertJsonPath('data.0.next_fixtures.0.rival_position', 4);
    // json_encode writes 1.0 as 1, so compare loosely.
    expect($response->json('data.0.next_fixtures.0.difficulty'))->toEqual(1.0);
});

test('adds the 30-day value multiple, points per million with rank and the owner\'s gain', function (): void {
    $season = signalsSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 50_000_000, 'points' => 20]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 10_000_000, 'points' => 10]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(30)->toDateString(), 'value' => 25_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString(), 'value' => 50_000_000]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'amount' => 45_000_000,
        'occurred_at' => now()->subDays(5),
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    expect($response->json('data.value_trend_30d.multiple'))->toEqual(2.0);
    $response->assertJsonPath('data.value_trend_30d.value', 25_000_000);
    $response->assertJsonPath('data.points_per_million.value', 0.4);
    $response->assertJsonPath('data.points_per_million.rank', 2);
    $response->assertJsonPath('data.points_per_million.ranked', 2);
    $response->assertJsonPath('data.owner_gain.amount', 5_000_000);
    $response->assertJsonPath('data.owner_gain.paid', 45_000_000);
    $response->assertJsonPath('data.owner_gain.type', 'signing');
});

test('sorts players by market trend strength', function (): void {
    signalsSeason();
    $falling = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_trend' => MarketTrend::FallSteady]);
    $flat = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_trend' => null]);
    $rising = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_trend' => MarketTrend::RiseAcceleratingSharply]);

    $this->getJson('/api/players?sort=trend')
        ->assertOk()
        ->assertJsonPath('data.0.id', $rising->id)
        ->assertJsonPath('data.1.id', $flat->id)
        ->assertJsonPath('data.2.id', $falling->id);

    $this->getJson('/api/players?sort=trend&direction=asc')
        ->assertJsonPath('data.0.id', $falling->id);
});

test('sorts players by points per million', function (): void {
    signalsSeason();
    $pricey = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 50_000_000, 'points' => 20]);
    $bargain = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 10_000_000, 'points' => 10]);
    $blank = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 5_000_000, 'points' => 0]);

    $this->getJson('/api/players?sort=points_per_million')
        ->assertOk()
        ->assertJsonPath('data.0.id', $bargain->id)
        ->assertJsonPath('data.1.id', $pricey->id)
        ->assertJsonPath('data.2.id', $blank->id);
});

test('filters free agents or owned players', function (): void {
    $season = signalsSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $owned->id]);
    $free = Player::factory()->create(['status' => PlayerStatus::Ok]);

    $this->getJson('/api/players?free=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $free->id);
    $this->getJson('/api/players?free=false')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owned->id);
});

test('filters players by a market value range', function (): void {
    signalsSeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 5_000_000]);
    $middle = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 20_000_000]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 80_000_000]);

    $this->getJson('/api/players?min_value=10000000&max_value=30000000')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $middle->id);
});

test('filters players by the start probability of their next match only', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $next = signalsNextFixture($season, $team, Team::factory()->create());
    $later = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $team->id,
        'date' => now()->addDays(9),
    ]);
    $likely = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $doubt = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $likely->id, 'probability' => 85]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $doubt->id, 'probability' => 40]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $later->id, 'player_id' => $doubt->id, 'probability' => 95]);

    $this->getJson('/api/players?min_start_probability=70')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $likely->id);
});

test('rejects invalid new filters with a 422', function (string $query, string $parameter): void {
    signalsSeason();

    $this->getJson("/api/players?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$parameter]);
})->with([
    'probability over 100' => ['min_start_probability=150', 'min_start_probability'],
    'free not boolean' => ['free=maybe', 'free'],
    'negative value' => ['min_value=-1', 'min_value'],
    'unknown sort' => ['sort=average', 'sort'],
]);
```

- [ ] **Step 2: Run them to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/PlayerSignalsTest.php`
Expected: FAIL. `next_start` and the metrics are missing, `rival_position` is missing, and the new filters and sorts return 422 as unknown parameters or values.

- [ ] **Step 3: Add `MarketTrend::strength()` and the sort enum**

In `app/Enums/MarketTrend.php`, add after `isRising()`:

```php
    /**
     * Ranks how strongly the value is moving, for sorting: +6 rising and
     * accelerating sharply … −6 falling and accelerating sharply. The
     * inflections sit next to zero (a player without a trend counts as 0).
     */
    public function strength(): int
    {
        return match ($this) {
            self::RiseAcceleratingSharply => 6,
            self::RiseAccelerating => 5,
            self::RiseSteady => 4,
            self::RiseDecelerating => 3,
            self::RiseDeceleratingSharply => 2,
            self::PositiveInflection => 1,
            self::NegativeInflection => -1,
            self::FallDeceleratingSharply => -2,
            self::FallDecelerating => -3,
            self::FallSteady => -4,
            self::FallAccelerating => -5,
            self::FallAcceleratingSharply => -6,
        };
    }
```

Create `app/Enums/ApiPlayerSort.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sorts of `/api/players`. The web keeps its own PlayerSort. `trend` orders
 * by MarketTrend::strength() and `points_per_million` by season points per
 * million of current value.
 */
enum ApiPlayerSort: string
{
    case Points = 'points';
    case Value = 'value';
    case Difference = 'difference';
    case Trend = 'trend';
    case PointsPerMillion = 'points_per_million';
}
```

- [ ] **Step 4: Add rival difficulty to the API next fixtures**

Replace `app/Http/Controllers/Concerns/AttachesApiNextFixtures.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Resources\TeamResource;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Services\LeagueStandings;
use Illuminate\Support\Collection;

trait AttachesApiNextFixtures
{
    /**
     * Same source data as AttachesNextFixtures (the web trait), reshaped for
     * the API:
     * - a variable-length list (0–3 entries, soonest first) instead of a
     *   null-padded fixed-length array;
     * - each entry carries the fixture's id and date, the rival's current
     *   real-table position and its difficulty (−1 leader … +1 last);
     * - a rival missing from the table gets nulls rather than a made-up
     *   mid-table rating.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachApiNextFixtures(Collection $players, Season $season): void
    {
        $eligiblePlayers = $players->filter(
            fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague,
        );
        $teamIds = $eligiblePlayers->pluck('team_id')->unique()->all();

        /** @var array<int, list<Fixture>> $fixturesByTeam */
        $fixturesByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds))
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get()
            ->each(function (Fixture $fixture) use ($teamIds, &$fixturesByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true)) {
                        $fixturesByTeam[$teamId][] = $fixture;
                    }
                }
            });

        $positions = $fixturesByTeam === [] ? [] : app(LeagueStandings::class)->positions($season);
        $teamCount = count($positions);

        $players->each(function (Player $player) use ($fixturesByTeam, $positions, $teamCount): void {
            if ($player->status === PlayerStatus::OutOfLeague) {
                $player->api_next_fixtures = [];

                return;
            }

            $player->api_next_fixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->map(function (Fixture $fixture) use ($player, $positions, $teamCount): array {
                    $isHome = $fixture->team_local_id === $player->team_id;
                    $opponent = $isHome ? $fixture->guestTeam : $fixture->localTeam;
                    $rivalPosition = $positions[$opponent->id] ?? null;

                    return [
                        'fixture_id' => $fixture->id,
                        'week_number' => $fixture->week_number,
                        'date' => $fixture->date->toIso8601String(),
                        'opponent' => (new TeamResource($opponent))->resolve(),
                        'is_home' => $isHome,
                        'rival_position' => $rivalPosition,
                        'difficulty' => $rivalPosition === null
                            ? null
                            : round(LeagueStandings::difficulty($rivalPosition, $teamCount), 3),
                    ];
                })
                ->values()
                ->all();
        });
    }
}
```

- [ ] **Step 5: Write the shared player shape service**

Create `app/Services/ApiPlayerShapes.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesApiNextFixtures;
use App\Http\Controllers\Concerns\AttachesApiRecentScores;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Http\Resources\TeamResource;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Attaches, in batch, everything PlayerResource / PlayerDetailResource show
 * beyond the Player row, for players of one season:
 * - owner and season figures;
 * - recent scores;
 * - next fixtures with rival difficulty;
 * - `next_start` (FútbolFantasy probability or the confirmed lineup);
 * - the value metrics.
 *
 * Every API surface that shows a player goes through this service, so the
 * shape is identical in /players, /players/{id}, /market and manager
 * rosters.
 *
 * @phpstan-import-type PlayerNextStart from StartProbabilities
 */
class ApiPlayerShapes
{
    use AttachesApiNextFixtures;
    use AttachesApiRecentScores;
    use AttachesCurrentPlayerSeason;
    use AttachesOwnerManager;

    public function __construct(
        private readonly StartProbabilities $startProbabilities,
        private readonly PlayerMarketMetrics $marketMetrics,
    ) {}

    /**
     * @param  Collection<int, Player>  $players  with `team` loaded
     */
    public function attach(Collection $players, Season $season): void
    {
        $this->attachOwnerManager($players, $season->id);
        $this->attachCurrentSeason($players, $season->id);
        $this->attachApiRecentScores($players, $season);
        $this->attachApiNextFixtures($players, $season);
        $this->attachNextStarts($players, $season);
        $this->attachValueMetrics($players, $season);
    }

    /**
     * The public shape of a `next_start`: StartProbabilities' block without
     * the Team model and the short name. `source` is the confirmed lineup's
     * source once there is one, else FútbolFantasy (always to be credited).
     *
     * @param  PlayerNextStart  $start
     * @return array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, source: string, is_stale: bool, fetched_at: string|null, source_url: string}
     */
    public static function presentNextStart(array $start): array
    {
        return [
            'fixture_id' => $start['fixture_id'],
            'week_number' => $start['week_number'],
            'date' => $start['date'],
            'opponent' => (new TeamResource($start['opponent']))->resolve(),
            'is_home' => $start['is_home'],
            'probability' => $start['probability'],
            'predicted_starter' => $start['predicted_starter'],
            'confirmed_starter' => $start['confirmed_starter'],
            'source' => $start['confirmed_source'] ?? 'futbolfantasy',
            'is_stale' => $start['is_stale'],
            'fetched_at' => $start['fetched_at'],
            'source_url' => $start['source_url'],
        ];
    }

    /**
     * Null when there is no data for the player's next match. That includes
     * a postponed match, a player FútbolFantasy doesn't list and an
     * out-of-league player. Null never means 0 %.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachNextStarts(Collection $players, Season $season): void
    {
        $nextStarts = $this->startProbabilities->forPlayersNextFixture($players, $season);

        $players->each(function (Player $player) use ($nextStarts): void {
            $nextStart = $nextStarts[$player->id] ?? null;

            $player->api_next_start = $nextStart === null ? null : self::presentNextStart($nextStart);
        });
    }

    /**
     * The 30-day value multiple, season points per million (with its rank)
     * and the owner's paper gain, batched. All three are null for a player
     * without season figures.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachValueMetrics(Collection $players, Season $season): void
    {
        $playerIds = $players->pluck('id')->all();

        $historyByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $playerIds)
            ->orderBy('date')
            ->get()
            ->groupBy('player_id');

        $owners = ManagerPlayer::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get()
            ->keyBy('player_id');

        $purchasesByPlayer = Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $playerIds)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('player_id');

        $players->each(function (Player $player) use ($season, $historyByPlayer, $owners, $purchasesByPlayer): void {
            $marketValue = $player->getAttribute('market_value');

            if (!is_int($marketValue)) {
                $player->api_value_trend_30d = null;
                $player->api_points_per_million = null;
                $player->api_owner_gain = null;

                return;
            }

            /** @var Collection<int, PlayerMarket> $history */
            $history = $historyByPlayer->get($player->id) ?? collect();

            /** @var Collection<int, Activity> $purchases */
            $purchases = $purchasesByPlayer->get($player->id) ?? collect();

            $player->api_value_trend_30d = $this->marketMetrics->valueTrend($marketValue, $history);
            $player->api_points_per_million = $player->status === PlayerStatus::OutOfLeague
                ? null
                : $this->marketMetrics->pointsPerMillion($player, $season);
            $player->api_owner_gain = $this->marketMetrics->capitalGain($marketValue, $owners->get($player->id), $purchases);
        });
    }
}
```

- [ ] **Step 6: Replace the API player filter**

Replace `app/Http/Filters/ApiPlayerFilter.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Filters;

use App\Enums\ApiPlayerSort;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SortDirection;
use Illuminate\Http\Request;

/**
 * `/api/players` filters. Validation (422) happens in the controller; this
 * only parses already-valid input.
 */
final class ApiPlayerFilter extends BaseRequestFilter
{
    /** @var PlayerPosition[] */
    private readonly array $positions;

    /** @var int[] */
    private readonly array $teams;

    /** @var int[] */
    private readonly array $managers;

    /** @var PlayerStatus[] */
    private readonly array $statuses;

    private readonly ?string $search;

    private readonly ?bool $free;

    private readonly ?int $minValue;

    private readonly ?int $maxValue;

    private readonly ?int $minStartProbability;

    private readonly ApiPlayerSort $sort;

    private readonly SortDirection $direction;

    public function __construct(Request $request)
    {
        $this->positions = $this->parseEnumList(PlayerPosition::class, $request->string('position')->toString());
        $this->teams = $this->parseIntList($request->string('team')->toString());
        $this->managers = $this->parseIntList($request->string('manager')->toString());
        $this->statuses = $this->parseEnumList(PlayerStatus::class, $request->string('status')->toString());
        $this->search = $this->parseString($request->string('search')->toString());
        $this->free = $request->filled('free') ? $request->boolean('free') : null;
        $this->minValue = $request->filled('min_value') ? $request->integer('min_value') : null;
        $this->maxValue = $request->filled('max_value') ? $request->integer('max_value') : null;
        $this->minStartProbability = $request->filled('min_start_probability') ? $request->integer('min_start_probability') : null;
        $this->sort = $this->parseEnum(ApiPlayerSort::class, $request->string('sort')->toString()) ?? ApiPlayerSort::Points;
        $this->direction = $this->parseEnum(SortDirection::class, $request->string('direction')->toString()) ?? SortDirection::Desc;
    }

    /**
     * @return PlayerPosition[]
     */
    public function getPositions(): array
    {
        return $this->positions;
    }

    /**
     * @return int[]
     */
    public function getTeams(): array
    {
        return $this->teams;
    }

    /**
     * @return int[]
     */
    public function getManagers(): array
    {
        return $this->managers;
    }

    /**
     * @return PlayerStatus[]
     */
    public function getStatuses(): array
    {
        return $this->statuses;
    }

    public function getSearch(): ?string
    {
        return $this->search;
    }

    /** True: only unowned players; false: only owned ones; null: both. */
    public function isFree(): ?bool
    {
        return $this->free;
    }

    public function getMinValue(): ?int
    {
        return $this->minValue;
    }

    public function getMaxValue(): ?int
    {
        return $this->maxValue;
    }

    public function getMinStartProbability(): ?int
    {
        return $this->minStartProbability;
    }

    public function getSort(): ApiPlayerSort
    {
        return $this->sort;
    }

    public function getDirection(): SortDirection
    {
        return $this->direction;
    }
}
```

- [ ] **Step 7: Rewrite the players controller**

Replace `app/Http/Controllers/Api/PlayersController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ApiPlayerSort;
use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Enums\SortDirection;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\ValidatesApiQuery;
use App\Http\Controllers\Controller;
use App\Http\Filters\ApiPlayerFilter;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\PlayerDetailResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\TeamResource;
use App\Models\Activity;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\ApiPlayerShapes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlayersController extends Controller
{
    use AttachesActivityValueDifference;
    use ValidatesApiQuery;

    private const array OWNERSHIP_ACTIVITY_TYPES = [
        SeasonActivityType::Signing,
        SeasonActivityType::Sale,
        SeasonActivityType::Buyout,
    ];

    /**
     * Diacritics found in LaLiga squads — see PlayersController's own copy for
     * the full rationale; kept identical here so search behaves the same way.
     */
    private const array ACCENT_FOLD = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
    ];

    /** Positions the API can filter by. Coaches are never in the API. */
    private const array FILTERABLE_POSITIONS = ['goalkeeper', 'defender', 'midfield', 'striker'];

    /** Statuses the list can filter by. Out-of-league players are never listed. */
    private const array FILTERABLE_STATUSES = ['ok', 'injured', 'doubtful', 'suspended'];

    /**
     * Sort rank of `player_seasons.market_trend`: one `WHEN ? THEN ?` per
     * MarketTrend case (12), filled by trendStrengthBindings(). A missing
     * trend counts as 0.
     */
    private const string TREND_STRENGTH_SQL = 'CASE player_seasons.market_trend'
        .' WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ?'
        .' WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ?'
        .' ELSE 0 END';

    /** Season points per million of current value; 0 without a value. */
    private const string POINTS_PER_MILLION_SQL = 'CASE WHEN player_seasons.market_value > 0'
        .' THEN player_seasons.points * 1.0 / player_seasons.market_value ELSE 0 END';

    public function __construct(private readonly ApiPlayerShapes $playerShapes) {}

    public function index(Request $request, ApiPlayerFilter $filter): AnonymousResourceCollection
    {
        $this->validateApiQuery($request, [
            'position' => ['sometimes', 'nullable', $this->commaSeparatedIn(self::FILTERABLE_POSITIONS)],
            'team' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'manager' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'status' => ['sometimes', 'nullable', $this->commaSeparatedIn(self::FILTERABLE_STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'free' => ['sometimes', 'nullable', Rule::in(['1', '0', 'true', 'false'])],
            'min_value' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_value' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'min_start_probability' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'sort' => ['sometimes', 'nullable', Rule::enum(ApiPlayerSort::class)],
            'direction' => ['sometimes', 'nullable', Rule::enum(SortDirection::class)],
        ]);

        $season = Season::current();

        $positions = $filter->getPositions();
        $teams = $filter->getTeams();
        $managers = $filter->getManagers();
        $statuses = $filter->getStatuses();
        $search = $filter->getSearch();
        $free = $filter->isFree();
        $minValue = $filter->getMinValue();
        $maxValue = $filter->getMaxValue();
        $minStartProbability = $filter->getMinStartProbability();
        $ownedThisSeason = fn ($query) => $query->whereHas(
            'seasonManager',
            fn ($query) => $query->where('season_id', $season->id),
        );

        $query = Player::query()
            ->select('players.*')
            ->join('player_seasons', function ($join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'players.id')
                    ->where('player_seasons.season_id', $season->id);
            })
            ->with('team')
            ->whereNotNull('fantasy_id')
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->when($positions !== [], fn ($query) => $query->whereIn('player_seasons.position', $positions))
            ->when($teams !== [], fn ($query) => $query->whereIn('team_id', $teams))
            ->when($managers !== [], fn ($query) => $query->whereHas(
                'seasonManagerPlayers',
                fn ($query) => $query->whereIn('season_manager_id', $managers),
            ))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($free === true, fn ($query) => $query->whereDoesntHave('seasonManagerPlayers', $ownedThisSeason))
            ->when($free === false, fn ($query) => $query->whereHas('seasonManagerPlayers', $ownedThisSeason))
            ->when($minValue !== null, fn ($query) => $query->where('player_seasons.market_value', '>=', $minValue))
            ->when($maxValue !== null, fn ($query) => $query->where('player_seasons.market_value', '<=', $maxValue))
            ->when($minStartProbability !== null, fn ($query) => $this->whereNextStartProbabilityAtLeast($query, $season, (int) $minStartProbability))
            ->when($search !== null, fn ($query) => $query->whereRaw(
                $this->foldedNicknameSql().' LIKE ?',
                ['%'.Str::lower(Str::ascii((string) $search)).'%'],
            ));

        $this->orderPlayers($query, $filter->getSort(), $filter->getDirection());

        $players = $query->paginate(15)->withQueryString();

        $this->playerShapes->attach($players->getCollection(), $season);

        return PlayerResource::collection($players);
    }

    public function show(Player $player): PlayerDetailResource
    {
        abort_if($player->fantasy_id === null, 404);

        $player->load('team');
        $season = Season::current();

        $this->playerShapes->attach(new Collection([$player]), $season);
        $this->attachMarketListing($player);
        $this->attachMarketHistory($player);
        $this->attachScores($player, $season);
        $this->attachOwnershipActivity($player, $season);

        return new PlayerDetailResource($player);
    }

    /**
     * @param  Builder<Player>  $query
     */
    private function orderPlayers(Builder $query, ApiPlayerSort $sort, SortDirection $direction): void
    {
        $ordered = match ($sort) {
            ApiPlayerSort::Points => $query->orderBy('player_seasons.points', $direction->value),
            ApiPlayerSort::Value => $query->orderBy('player_seasons.market_value', $direction->value),
            ApiPlayerSort::Difference => $query->orderBy('player_seasons.market_value_difference', $direction->value),
            ApiPlayerSort::Trend => $query
                ->orderByRaw(self::TREND_STRENGTH_SQL.' '.$direction->value, $this->trendStrengthBindings())
                ->orderBy('player_seasons.market_value_difference', $direction->value),
            ApiPlayerSort::PointsPerMillion => $query->orderByRaw(self::POINTS_PER_MILLION_SQL.' '.$direction->value),
        };

        $ordered->orderBy('players.id');
    }

    /**
     * @return list<int|string>
     */
    private function trendStrengthBindings(): array
    {
        $bindings = [];

        foreach (MarketTrend::cases() as $trend) {
            $bindings[] = $trend->value;
            $bindings[] = $trend->strength();
        }

        return $bindings;
    }

    /**
     * Keeps players whose FútbolFantasy start probability for their team's
     * next match is at least `$minimum`. The next match is the first scheduled
     * one still to come, the same one `next_start` describes. A confirmed
     * lineup is not considered here; `next_start.confirmed_starter` says so.
     *
     * @param  Builder<Player>  $query
     */
    private function whereNextStartProbabilityAtLeast(Builder $query, Season $season, int $minimum): void
    {
        $query->whereExists(fn ($exists) => $exists
            ->selectRaw('1')
            ->from('fixture_lineup_probabilities')
            ->whereColumn('fixture_lineup_probabilities.player_id', 'players.id')
            ->where('fixture_lineup_probabilities.probability', '>=', $minimum)
            ->where('fixture_lineup_probabilities.fixture_id', fn ($next) => $next
                ->select('fixtures.id')
                ->from('fixtures')
                ->where('fixtures.season_id', $season->id)
                ->where('fixtures.state', FixtureState::Scheduled->value)
                ->where('fixtures.date', '>', now())
                ->where(fn ($teams) => $teams
                    ->whereColumn('fixtures.team_local_id', 'players.team_id')
                    ->orWhereColumn('fixtures.team_guest_id', 'players.team_id'))
                ->orderBy('fixtures.date')
                ->limit(1)));
    }

    private function attachMarketListing(Player $player): void
    {
        $listing = MarketPlayer::query()->where('player_id', $player->id)->first();

        $player->api_market_listing = $listing === null ? null : [
            'sale_price' => $listing->sale_price,
            'market_value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
            'seller' => MarketPlayer::SELLER_LEAGUE,
        ];
    }

    private function attachMarketHistory(Player $player): void
    {
        $player->api_market_history = PlayerMarket::query()
            ->where('player_id', $player->id)
            ->orderBy('date')
            ->get(['date', 'value'])
            ->map(fn (PlayerMarket $market): array => [
                'date' => $market->date->toDateString(),
                'value' => $market->value,
            ])
            ->all();
    }

    private function attachScores(Player $player, Season $season): void
    {
        $lineups = $player->fixtureLineups()
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with(['fixture.localTeam', 'fixture.guestTeam'])
            ->get()
            ->sortBy(fn (FixtureLineup $lineup) => $lineup->fixture->week_number)
            ->values();

        $lineupManagersByFixture = ManagerLineupPlayer::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', $lineups->pluck('fixture_id')->filter())
            ->whereHas('lineup.seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('fixture_id');

        $player->api_scores = $lineups
            ->map(function (FixtureLineup $lineup) use ($lineupManagersByFixture): array {
                $fixture = $lineup->fixture;
                $isHome = $fixture->team_local_id === $lineup->team_id;
                $seasonManager = $lineupManagersByFixture->get($fixture->id)?->lineup?->seasonManager;

                return [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'opponent' => (new TeamResource($isHome ? $fixture->guestTeam : $fixture->localTeam))->resolve(),
                    'is_home' => $isHome,
                    'points' => $lineup->fantasy_points,
                    'stats' => $lineup->fantasy_stats,
                    'lineup_manager' => $seasonManager === null ? null : [
                        'id' => $seasonManager->id,
                        'name' => $seasonManager->name,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    private function attachOwnershipActivity(Player $player, Season $season): void
    {
        $activity = Activity::query()
            ->where('season_id', $season->id)
            ->where('player_id', $player->id)
            ->whereIn('type', self::OWNERSHIP_ACTIVITY_TYPES)
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderBy('occurred_at')
            ->get();

        $this->attachValueDifferences($activity);

        $player->api_ownership_activity = ActivityResource::collection($activity)->resolve();
    }

    /** @return literal-string */
    private function foldedNicknameSql(): string
    {
        $expression = 'LOWER(nickname)';

        foreach (self::ACCENT_FOLD as $accented => $plain) {
            $expression = "REPLACE({$expression}, '{$accented}', '{$plain}')";
        }

        return $expression;
    }
}
```

- [ ] **Step 8: Use the shared shape in the market**

Replace `app/Http/Controllers/Api/MarketController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PlayerPosition;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerResource;
use App\Models\MarketPlayer;
use App\Models\Season;
use App\Services\ApiPlayerShapes;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    public function index(ApiPlayerShapes $playerShapes): JsonResponse
    {
        $season = Season::current();

        $listings = MarketPlayer::query()
            ->with(['player.team'])
            ->whereHas('player.seasons', fn ($query) => $query
                ->where('season_id', $season->id)
                ->where('position', '!=', PlayerPosition::Coach))
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        $playerShapes->attach($listings->pluck('player'), $season);

        $data = $listings->map(fn (MarketPlayer $listing): array => [
            'player' => (new PlayerResource($listing->player))->resolve(),
            'sale_price' => $listing->sale_price,
            'market_value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
            'seller' => MarketPlayer::SELLER_LEAGUE,
        ]);

        return response()->json(['data' => $data]);
    }
}
```

- [ ] **Step 9: Add the new fields to the player resources and docblock**

In `app/Http/Resources/PlayerResource.php`, make `toArray()` return:

```php
        return [
            'id' => $this->id,
            'url' => route('api.players.show', $this->id),
            'nickname' => $this->nickname,
            'image' => $this->image ? asset('storage/'.$this->image) : '',
            'status' => $this->status->value,
            'position' => $this->position?->value,
            'team' => new TeamResource($this->team),
            'market_value' => $this->market_value,
            'market_value_difference' => $this->market_value_difference,
            'market_trend' => $this->market_trend?->value,
            'points' => $this->points,
            'average_points' => (float) $this->average_points,
            'owner_manager' => $this->owner_manager,
            'recent_scores' => $this->api_recent_scores,
            'next_fixtures' => $this->api_next_fixtures,
            'next_start' => $this->api_next_start,
            'value_trend_30d' => $this->api_value_trend_30d,
            'points_per_million' => $this->api_points_per_million,
            'owner_gain' => $this->api_owner_gain,
        ];
```

In `app/Http/Resources/PlayerDetailResource.php`, make `toArray()` return:

```php
        return [
            'id' => $this->id,
            'url' => route('api.players.show', $this->id),
            'nickname' => $this->nickname,
            'image' => $this->image ? asset('storage/'.$this->image) : '',
            'status' => $this->status->value,
            'position' => $this->position?->value,
            'team' => new TeamResource($this->team),
            'market_value' => $this->market_value,
            'market_value_difference' => $this->market_value_difference,
            'market_trend' => $this->market_trend?->value,
            'points' => $this->points,
            'average_points' => (float) $this->average_points,
            'owner_manager' => $this->owner_manager,
            'next_fixtures' => $this->api_next_fixtures,
            'next_start' => $this->api_next_start,
            'value_trend_30d' => $this->api_value_trend_30d,
            'points_per_million' => $this->api_points_per_million,
            'owner_gain' => $this->api_owner_gain,
            'market_listing' => $this->api_market_listing,
            'market_history' => $this->api_market_history,
            'scores' => $this->api_scores,
            'ownership_activity' => $this->api_ownership_activity,
        ];
```

In `app/Models/Player.php`, replace the `api_next_fixtures` docblock line with the first line below and add the other four after it:

```php
 * @property array<int, array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, rival_position: int|null, difficulty: float|null}> $api_next_fixtures The team's next (up to) 3 scheduled matches, soonest first, each with the rival's real-table position and difficulty (−1 leader … +1 last; null when the rival isn't in the table). Unlike next_fixtures, no padding. `opponent` is a resolved TeamResource. Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, source: string, is_stale: bool, fetched_at: string|null, source_url: string}|null $api_next_start Start probability (or confirmed lineup) for the team's next match, null without data. Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{multiple: float, value: int, date: string}|null $api_value_trend_30d Current value ÷ the value ~30 days earlier (PlayerMarketMetrics::valueTrend). Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{value: float, rank: int|null, ranked: int}|null $api_points_per_million Season points per million of value, with its league rank (PlayerMarketMetrics::pointsPerMillion). Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{amount: int, paid: int, type: string, occurred_at: string}|null $api_owner_gain Current value minus what the owner paid in his latest signing/buyout (PlayerMarketMetrics::capitalGain). Computed at query time by ApiPlayerShapes; not a database column.
```

- [ ] **Step 10: Run the tests to verify they pass**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api`
Expected: PASS, including `PlayerSignalsTest`, the Task 2 tests and `MaxBidGuardTest`.

- [ ] **Step 11: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Services/ApiPlayerShapes.php app/Enums/ApiPlayerSort.php app/Enums/MarketTrend.php app/Http/Controllers/Concerns/AttachesApiNextFixtures.php app/Http/Filters/ApiPlayerFilter.php app/Http/Controllers/Api/PlayersController.php app/Http/Controllers/Api/MarketController.php app/Http/Resources/PlayerResource.php app/Http/Resources/PlayerDetailResource.php app/Models/Player.php tests/Feature/Http/Controllers/Api/PlayerSignalsTest.php
git commit -m "feat: add next start, rival difficulty and value metrics to every API player, with new sorts and filters" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---
### Task 5: The manager ficha: current lineup, finished history, full roster, ranks and value change

**Files:**
- Modify: `app/Services/SeasonClock.php` (adds `finishedWeekNumbers()` and `lineupWeek()`)
- Create: `app/Services/ManagerWeekRanks.php`
- Modify: `app/Http/Controllers/SeasonManagersController.php` (uses the service; the private `weekRanks()` is deleted)
- Create: `app/Http/Controllers/Concerns/AttachesDailyValueDifference.php`
- Modify: `app/Http/Controllers/HomeController.php` (uses the concern; the private method is deleted)
- Modify: `app/Http/Controllers/Api/ManagerController.php` (whole file)
- Modify: `app/Http/Resources/ManagerResource.php` (whole `toArray()`)
- Modify: `app/Models/SeasonManager.php` (docblock)
- Test: `tests/Feature/Http/Controllers/Api/ManagerControllerTest.php` (whole file)

**Interfaces:**
- Consumes:
  - `SeasonClock::weekState()`, `firstKickoff()` and `LIVE` (Task 3).
  - `ApiPlayerShapes::attach()` and `ApiPlayerShapes::presentNextStart()` (Task 4).
  - `StartProbabilities::forPlayersNextFixture()` (existing).
  - `AttachesLineupFixtures`, `AttachesLineupPlayerScores` and `AttachesMatchFinished` (existing).
- Produces:
  - `SeasonClock::finishedWeekNumbers(Season $season): list<int>`.
  - `SeasonClock::lineupWeek(Season $season): int` returns the current jornada until it has finished, then the next one.
  - `App\Services\ManagerWeekRanks::forManager(SeasonManager $seasonManager, Season $season, array<int, int> $weekNumbers): array<int, array{rank: int, managers: int, points: int, is_last: bool}>`.
  - Trait `AttachesDailyValueDifference::attachDailyValueDifference(Collection<int, SeasonManager> $seasonManagers, Season $season): void` sets `daily_value_difference`.
  - `/api/managers/{id}` fields:
    - Profile and form: `rank`, `last_rank`, `total_points`, `live_points`, `squad_value`, `daily_value_difference`, `played_weeks`, `average_points`, `week_ranks[]`.
    - Lineups: `current_lineup` and `lineup_history[]` (finished jornadas only).
    - Squad and activity: `roster[]` (`player` in the full PlayerResource shape, `purchase` and a `buyout_clause` object) and `recent_activity[]`.

- [ ] **Step 1: Write the failing tests**

Replace `tests/Feature/Http/Controllers/Api/ManagerControllerTest.php` with:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

function managerApiSeason(int $currentWeek = 1): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(200),
        'current_week' => $currentWeek,
    ]);
}

function managerApiFixture(Season $season, int $weekNumber, FixtureState $state, Team $local, mixed $date): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => $weekNumber,
        'state' => $state,
        'team_local_id' => $local->id,
        'date' => $date,
    ]);
}

test('returns the manager info fields', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'name' => 'Comando Lechuga',
        'position' => 1,
        'last_position' => 2,
        'total_points' => 812,
        'value' => 123_456_789,
        'live_points' => 40,
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $manager->id);
    $response->assertJsonPath('data.url', route('api.managers.show', $manager->id));
    $response->assertJsonPath('data.name', 'Comando Lechuga');
    $response->assertJsonPath('data.rank', 1);
    $response->assertJsonPath('data.last_rank', 2);
    $response->assertJsonPath('data.total_points', 812);
    $response->assertJsonPath('data.squad_value', 123_456_789);
    $response->assertJsonPath('data.live_points', null);
});

test('returns 404 for a manager that does not exist', function (): void {
    $this->getJson('/api/managers/999999')->assertNotFound();
});

test('returns each roster player in the full player shape with his purchase and clause', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $player = Player::factory()->create([
        'nickname' => 'Pedri',
        'status' => PlayerStatus::Ok,
        'team_id' => $team->id,
        'average_points' => 6.5,
    ]);
    $lockedUntil = now()->addDays(3);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'buyout_clause' => 90_000_000,
        'buyout_clause_locked_until' => $lockedUntil,
        'shielded' => true,
        'shielded_until' => now()->addDay(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'amount' => 70_000_000,
        'occurred_at' => now()->subDays(5),
    ]);
    $next = managerApiFixture($season, 2, FixtureState::Scheduled, $team, now()->addDays(2));
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $player->id, 'probability' => 80]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.roster');
    $response->assertJsonPath('data.roster.0.player.id', $player->id);
    $response->assertJsonPath('data.roster.0.player.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.roster.0.player.status', 'ok');
    $response->assertJsonPath('data.roster.0.player.average_points', 6.5);
    $response->assertJsonPath('data.roster.0.player.next_start.probability', 80);
    $response->assertJsonPath('data.roster.0.player.owner_manager.id', $manager->id);
    $response->assertJsonPath('data.roster.0.purchase.amount', 70_000_000);
    $response->assertJsonPath('data.roster.0.purchase.type', 'signing');
    $response->assertJsonPath('data.roster.0.buyout_clause.amount', 90_000_000);
    $response->assertJsonPath('data.roster.0.buyout_clause.locked_until', $lockedUntil->toIso8601String());
    $response->assertJsonPath('data.roster.0.buyout_clause.is_locked', true);
    $response->assertJsonPath('data.roster.0.buyout_clause.shielded', true);
});

test('splits the current jornada\'s lineup from the finished lineup history', function (): void {
    $season = managerApiSeason(2);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id, 'position' => PlayerPosition::Midfield]);
    managerApiFixture($season, 1, FixtureState::Finished, $team, now()->subDays(6));
    managerApiFixture($season, 2, FixtureState::Postponed, Team::factory()->create(), now()->addDay());
    $lock = now()->addDays(2)->setTime(18, 30);
    $next = managerApiFixture($season, 2, FixtureState::Scheduled, $team, $lock);
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $player->id, 'probability' => 75]);

    $week1 = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 45, 'tactical_formation' => [4, 4, 2]]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $week1->id, 'player_id' => $player->id, 'position' => PlayerPosition::Midfield, 'points' => 9]);
    $week2 = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2, 'points' => 0, 'tactical_formation' => [4, 3, 3]]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $week2->id, 'player_id' => $player->id, 'position' => PlayerPosition::Midfield, 'points' => null]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.lineup_history');
    $response->assertJsonPath('data.lineup_history.0.week_number', 1);
    $response->assertJsonPath('data.lineup_history.0.formation', '4-4-2');
    $response->assertJsonPath('data.lineup_history.0.tactical_formation', [4, 4, 2]);
    $response->assertJsonPath('data.lineup_history.0.players.0.points', 9);
    $response->assertJsonPath('data.lineup_history.0.players.0.match_finished', true);
    $response->assertJsonPath('data.current_lineup.week_number', 2);
    $response->assertJsonPath('data.current_lineup.week_state', 'not_started');
    $response->assertJsonPath('data.current_lineup.formation', '4-3-3');
    $response->assertJsonPath('data.current_lineup.lineup_locks_at', $lock->toIso8601String());
    $response->assertJsonPath('data.current_lineup.players.0.player.id', $player->id);
    $response->assertJsonPath('data.current_lineup.players.0.position', 'midfield');
    $response->assertJsonPath('data.current_lineup.players.0.match.fixture_id', $next->id);
    $response->assertJsonPath('data.current_lineup.players.0.match.state', 'scheduled');
    $response->assertJsonPath('data.current_lineup.players.0.points', null);
    $response->assertJsonPath('data.current_lineup.players.0.next_start.probability', 75);
});

test('shows live points and no next start for a lineup player whose match is live', function (): void {
    $season = managerApiSeason(2);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 33]);
    $liveTeam = Team::factory()->create();
    $laterTeam = Team::factory()->create();
    $playing = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $liveTeam->id]);
    $waiting = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $laterTeam->id]);
    $live = managerApiFixture($season, 2, FixtureState::FirstHalf, $liveTeam, now()->subMinutes(30));
    $later = managerApiFixture($season, 2, FixtureState::Scheduled, $laterTeam, now()->addDay());
    FixtureLineup::factory()->create(['fixture_id' => $live->id, 'player_id' => $playing->id, 'team_id' => $liveTeam->id, 'fantasy_points' => 7]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $live->id, 'player_id' => $playing->id, 'probability' => 90]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $later->id, 'player_id' => $waiting->id, 'probability' => 65]);

    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $playing->id, 'fixture_id' => $live->id, 'position' => PlayerPosition::Striker]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $waiting->id, 'fixture_id' => null, 'position' => PlayerPosition::Defender]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.live_points', 33);
    $response->assertJsonPath('data.current_lineup.week_state', 'live');
    $response->assertJsonPath('data.current_lineup.players.0.match.state', 'first_half');
    $response->assertJsonPath('data.current_lineup.players.0.points', 7);
    $response->assertJsonPath('data.current_lineup.players.0.next_start', null);
    $response->assertJsonPath('data.current_lineup.players.1.points', null);
    $response->assertJsonPath('data.current_lineup.players.1.next_start.probability', 65);
});

test('has a null current lineup when the manager has none for the jornada', function (): void {
    $season = managerApiSeason(2);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    managerApiFixture($season, 2, FixtureState::Scheduled, Team::factory()->create(), now()->addDay());

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.current_lineup', null);
    $response->assertJsonPath('data.lineup_history', []);
});

test('ranks the manager in each finished jornada and averages only jornadas with a lineup', function (): void {
    $season = managerApiSeason(3);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $rival = SeasonManager::factory()->create(['season_id' => $season->id]);
    managerApiFixture($season, 3, FixtureState::Scheduled, Team::factory()->create(), now()->addDay());
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 50]);
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2, 'points' => 70]);
    ManagerLineup::factory()->create(['season_manager_id' => $rival->id, 'week_number' => 1, 'points' => 60]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.played_weeks', 2);
    expect($response->json('data.average_points'))->toEqual(60.0);
    $response->assertJsonPath('data.week_ranks', [
        ['week_number' => 1, 'rank' => 2, 'managers' => 2, 'points' => 50, 'is_last' => true],
        ['week_number' => 2, 'rank' => 1, 'managers' => 1, 'points' => 70, 'is_last' => false],
    ]);
});

test('sums the squad\'s daily value difference', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    foreach ([300_000, -100_000] as $difference) {
        ManagerPlayer::factory()->create([
            'season_manager_id' => $manager->id,
            'player_id' => Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value_difference' => $difference])->id,
        ]);
    }

    $this->getJson("/api/managers/{$manager->id}")->assertJsonPath('data.daily_value_difference', 200_000);
});

test('returns the manager\'s last 10 activities as source or target, newest first', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $asTarget = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'target_season_manager_id' => $manager->id,
        'occurred_at' => now(),
    ]);
    $asSource = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'occurred_at' => now()->subMinute(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'occurred_at' => now(),
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(2, 'data.recent_activity');
    $response->assertJsonPath('data.recent_activity.0.id', $asTarget->id);
    $response->assertJsonPath('data.recent_activity.1.id', $asSource->id);
});
```

Whole-number floats are compared with `toEqual()`, not `assertJsonPath()`. `json_encode` writes `60.0` as `60`, so the decoded value is an int, and `assertJsonPath` compares strictly.

- [ ] **Step 2: Run them to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/ManagerControllerTest.php`
Expected: FAIL. `buyout_clause.amount`, `current_lineup`, `week_ranks`, `daily_value_difference` and the full roster shape don't exist yet.

- [ ] **Step 3: Add the finished weeks and the lineup week to `SeasonClock`**

In `app/Services/SeasonClock.php`, add after `upcomingWeek()`:

```php
    /**
     * Finished jornadas: every one before the current jornada (trusted as
     * past even with a postponed match left), plus the current one once it
     * has finished.
     *
     * @return list<int>
     */
    public function finishedWeekNumbers(Season $season): array
    {
        $weeks = $season->current_week > 1 ? range(1, $season->current_week - 1) : [];

        if ($this->weekState($season, $season->current_week) === self::FINISHED) {
            $weeks[] = $season->current_week;
        }

        return $weeks;
    }

    /**
     * The jornada a manager's "current" lineup belongs to: the current one
     * until it has finished, then the next (never past the last jornada).
     */
    public function lineupWeek(Season $season): int
    {
        if ($this->weekState($season, $season->current_week) !== self::FINISHED) {
            return $season->current_week;
        }

        return min($season->current_week + 1, $season->total_weeks);
    }
```

- [ ] **Step 4: Extract the per-jornada rank into a service**

Create `app/Services/ManagerWeekRanks.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;

/**
 * A manager's place among the season's managers in each given jornada he
 * has a lineup for. Shared by the manager ficha and the public API.
 */
class ManagerWeekRanks
{
    /**
     * Ranked by lineup points. Ties share the better place (two managers
     * tied on top are both 1º); `is_last` flags a share of the bottom.
     * Jornadas without this manager's lineup are skipped.
     *
     * @param  array<int, int>  $weekNumbers
     * @return array<int, array{rank: int, managers: int, points: int, is_last: bool}>
     */
    public function forManager(SeasonManager $seasonManager, Season $season, array $weekNumbers): array
    {
        if ($weekNumbers === []) {
            return [];
        }

        $lineupsByWeek = ManagerLineup::query()
            ->whereIn('week_number', $weekNumbers)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get(['season_manager_id', 'week_number', 'points'])
            ->groupBy('week_number');

        $weekRanks = [];

        foreach ($lineupsByWeek as $weekNumber => $weekLineups) {
            $ownLineup = $weekLineups->firstWhere('season_manager_id', $seasonManager->id);

            if (!$ownLineup instanceof ManagerLineup) {
                continue;
            }

            $weekRanks[(int) $weekNumber] = [
                'rank' => 1 + $weekLineups->filter(fn (ManagerLineup $lineup): bool => $lineup->points > $ownLineup->points)->count(),
                'managers' => $weekLineups->count(),
                'points' => $ownLineup->points,
                'is_last' => $weekLineups->count() > 1 && $ownLineup->points === $weekLineups->min('points'),
            ];
        }

        ksort($weekRanks);

        return $weekRanks;
    }
}
```

In `app/Http/Controllers/SeasonManagersController.php`:
- Add `use App\Services\ManagerWeekRanks;` to the imports.
- Change the `show` signature to `public function show(SeasonManager $seasonManager, StartProbabilities $startProbabilities, ManagerWeekRanks $managerWeekRanks): Response`.
- Replace `$weekRanks = $this->weekRanks($seasonManager, $season, $startedWeeks);` with `$weekRanks = $managerWeekRanks->forManager($seasonManager, $season, $startedWeeks);`.
- Delete the whole private `weekRanks()` method and its docblock.

- [ ] **Step 5: Extract the squad daily value change into a concern**

Create `app/Http/Controllers/Concerns/AttachesDailyValueDifference.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

trait AttachesDailyValueDifference
{
    /**
     * Attaches how much each manager's current squad gained or lost in the
     * latest daily market update: the sum of its players' daily value
     * differences. A manager without players gets 0.
     *
     * @param  Collection<int, SeasonManager>  $seasonManagers
     */
    private function attachDailyValueDifference(Collection $seasonManagers, Season $season): void
    {
        $differences = ManagerPlayer::query()
            ->join('player_seasons', function (JoinClause $join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'manager_players.player_id')
                    ->where('player_seasons.season_id', $season->id);
            })
            ->whereIn('manager_players.season_manager_id', $seasonManagers->pluck('id'))
            ->groupBy('manager_players.season_manager_id')
            ->selectRaw('manager_players.season_manager_id, SUM(player_seasons.market_value_difference) as daily_value_difference')
            ->pluck('daily_value_difference', 'season_manager_id');

        $seasonManagers->each(function (SeasonManager $manager) use ($differences): void {
            $manager->daily_value_difference = (int) ($differences->get($manager->id) ?? 0);
        });
    }
}
```

In `app/Http/Controllers/HomeController.php`:
- Add `use App\Http\Controllers\Concerns\AttachesDailyValueDifference;` to the imports.
- Add `use AttachesDailyValueDifference;` to the class's trait list (after `use AttachesCurrentPlayerSeason;`).
- Delete the private `attachDailyValueDifference()` method with its docblock.
- Remove the now-unused imports `use App\Models\ManagerPlayer;`, `use Illuminate\Database\Query\JoinClause;` and `use Illuminate\Support\Collection;`. After the deletion, none of them is referenced anywhere else in the file.
- The call `$this->attachDailyValueDifference($standings, $season);` stays unchanged.

- [ ] **Step 6: Rewrite the API manager controller and resource**

Replace `app/Http/Controllers/Api/ManagerController.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesDailyValueDifference;
use App\Http\Controllers\Concerns\AttachesLineupFixtures;
use App\Http\Controllers\Concerns\AttachesLineupPlayerScores;
use App\Http\Controllers\Concerns\AttachesMatchFinished;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ManagerResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\TeamResource;
use App\Models\Activity;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ApiPlayerShapes;
use App\Services\LeagueStandings;
use App\Services\ManagerWeekRanks;
use App\Services\SeasonClock;
use App\Services\StartProbabilities;

class ManagerController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesCurrentPlayerSeason;
    use AttachesDailyValueDifference;
    use AttachesLineupFixtures;
    use AttachesLineupPlayerScores;
    use AttachesMatchFinished;

    public function __construct(
        private readonly ApiPlayerShapes $playerShapes,
        private readonly SeasonClock $clock,
        private readonly ManagerWeekRanks $managerWeekRanks,
        private readonly StartProbabilities $startProbabilities,
    ) {}

    public function show(SeasonManager $seasonManager): ManagerResource
    {
        $season = $seasonManager->season;
        $finishedWeeks = $this->clock->finishedWeekNumbers($season);

        if ($this->clock->weekState($season, $season->current_week) !== SeasonClock::LIVE) {
            $seasonManager->live_points = null;
        }

        $this->attachDailyValueDifference(collect([$seasonManager]), $season);
        $this->attachWeekRanks($seasonManager, $season, $finishedWeeks);
        $this->attachRoster($seasonManager, $season);
        $this->attachLineups($seasonManager, $season, $finishedWeeks);
        $this->attachRecentActivity($seasonManager, $season);

        return new ManagerResource($seasonManager);
    }

    /**
     * Rank per finished jornada, and the average over the jornadas the
     * manager actually had a lineup for (a manager who joined mid-season
     * isn't averaged over jornadas he didn't play).
     *
     * @param  list<int>  $finishedWeeks
     */
    private function attachWeekRanks(SeasonManager $seasonManager, Season $season, array $finishedWeeks): void
    {
        $weekRanks = $this->managerWeekRanks->forManager($seasonManager, $season, $finishedWeeks);
        $playedWeeks = count($weekRanks);

        $seasonManager->api_week_ranks = collect($weekRanks)
            ->map(fn (array $weekRank, int $weekNumber): array => ['week_number' => $weekNumber, ...$weekRank])
            ->values()
            ->all();
        $seasonManager->api_played_weeks = $playedWeeks;
        $seasonManager->api_average_points = $playedWeeks > 0
            ? round(array_sum(array_column($weekRanks, 'points')) / $playedWeeks, 2)
            : null;
    }

    private function attachRoster(SeasonManager $seasonManager, Season $season): void
    {
        $roster = ManagerPlayer::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('player.team')
            ->get();

        $players = $roster->pluck('player');
        $this->playerShapes->attach($players, $season);

        $purchases = Activity::query()
            ->where('season_id', $season->id)
            ->where('source_season_manager_id', $seasonManager->id)
            ->whereIn('player_id', $players->pluck('id'))
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->whereNotNull('amount')
            ->orderBy('occurred_at')
            ->get()
            ->keyBy('player_id');

        $seasonManager->api_roster = $roster->map(function (ManagerPlayer $entry) use ($purchases): array {
            $purchase = $purchases->get($entry->player_id);

            return [
                'player' => (new PlayerResource($entry->player))->resolve(),
                'purchase' => $purchase === null ? null : [
                    'amount' => $purchase->amount,
                    'type' => $purchase->type->value,
                    'occurred_at' => $purchase->occurred_at->toIso8601String(),
                ],
                'buyout_clause' => [
                    'amount' => $entry->buyout_clause,
                    'locked_until' => $entry->buyout_clause_locked_until->toIso8601String(),
                    'is_locked' => $entry->buyout_clause_locked_until->isFuture(),
                    'shielded' => $entry->shielded,
                    'shielded_until' => $entry->shielded_until?->toIso8601String(),
                ],
            ];
        })->all();
    }

    /**
     * `lineup_history` holds finished jornadas only. `current_lineup` is the
     * lineup of the jornada being played or next to play (see
     * SeasonClock::lineupWeek()); it is null without one.
     *
     * @param  list<int>  $finishedWeeks
     */
    private function attachLineups(SeasonManager $seasonManager, Season $season, array $finishedWeeks): void
    {
        $lineupWeek = $this->clock->lineupWeek($season);

        $lineups = ManagerLineup::query()
            ->where('season_manager_id', $seasonManager->id)
            ->whereIn('week_number', [...$finishedWeeks, $lineupWeek])
            ->with('players.player.team')
            ->orderBy('week_number')
            ->get();

        $this->attachCurrentSeason($lineups->flatMap(fn (ManagerLineup $lineup) => $lineup->players->pluck('player')), $season->id);
        $this->attachMatchFinished($lineups, $season);
        $this->attachLineupPlayerScores($lineups);
        $this->attachLineupFixtures($lineups, $season);

        $seasonManager->api_lineup_history = $lineups
            ->filter(fn (ManagerLineup $lineup): bool => in_array($lineup->week_number, $finishedWeeks, true))
            ->map(fn (ManagerLineup $lineup): array => $this->presentFinishedLineup($lineup))
            ->values()
            ->all();

        $currentLineup = in_array($lineupWeek, $finishedWeeks, true)
            ? null
            : $lineups->firstWhere('week_number', $lineupWeek);

        $seasonManager->api_current_lineup = $currentLineup instanceof ManagerLineup
            ? $this->presentCurrentLineup($currentLineup, $season)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFinishedLineup(ManagerLineup $lineup): array
    {
        return [
            'week_number' => $lineup->week_number,
            'points' => $lineup->points,
            'formation' => implode('-', $lineup->tactical_formation),
            'tactical_formation' => $lineup->tactical_formation,
            'players' => $lineup->players->map(fn (ManagerLineupPlayer $entry): array => [
                'player' => [
                    'id' => $entry->player->id,
                    'nickname' => $entry->player->nickname,
                    'image' => $entry->player->image ? asset('storage/'.$entry->player->image) : '',
                ],
                'position' => $entry->position->value,
                'points' => $entry->points,
                'match_finished' => $entry->match_finished,
            ])->all(),
        ];
    }

    /**
     * Each pick's points only once his match has kicked off (live, then
     * final). `next_start` only applies while his match is still to be
     * played: it is null once the match kicks off, and when it is postponed.
     *
     * @return array<string, mixed>
     */
    private function presentCurrentLineup(ManagerLineup $lineup, Season $season): array
    {
        $nextStarts = $this->startProbabilities->forPlayersNextFixture($lineup->players->pluck('player'), $season);
        $kickedOffStates = [...LeagueStandings::LIVE_STATES, FixtureState::Finished];

        return [
            'week_number' => $lineup->week_number,
            'week_state' => $this->clock->weekState($season, $lineup->week_number),
            'formation' => implode('-', $lineup->tactical_formation),
            'tactical_formation' => $lineup->tactical_formation,
            'lineup_locks_at' => $this->clock->firstKickoff($season, $lineup->week_number)?->toIso8601String(),
            'points' => $lineup->points,
            'players' => $lineup->players->map(function (ManagerLineupPlayer $entry) use ($nextStarts, $kickedOffStates): array {
                $fixture = $entry->fixture;
                $kickedOff = $fixture !== null && in_array($fixture->state, $kickedOffStates, true);
                $nextStart = $nextStarts[$entry->player_id] ?? null;

                return [
                    'player' => [
                        'id' => $entry->player->id,
                        'url' => route('api.players.show', $entry->player->id),
                        'nickname' => $entry->player->nickname,
                        'image' => $entry->player->image ? asset('storage/'.$entry->player->image) : '',
                        'status' => $entry->player->status->value,
                        'position' => $entry->player->position?->value,
                        'team' => (new TeamResource($entry->player->team))->resolve(),
                    ],
                    'position' => $entry->position->value,
                    'match' => $fixture === null ? null : [
                        'fixture_id' => $fixture->id,
                        'url' => route('api.fixtures.show', $fixture->id),
                        'state' => $fixture->state->value,
                        'date' => $fixture->date->toIso8601String(),
                        'display_clock' => $fixture->display_clock,
                    ],
                    'points' => $kickedOff ? $entry->points : null,
                    'match_finished' => $entry->match_finished,
                    'next_start' => $fixture !== null && !$kickedOff && $nextStart !== null && $nextStart['fixture_id'] === $fixture->id
                        ? ApiPlayerShapes::presentNextStart($nextStart)
                        : null,
                ];
            })->all(),
        ];
    }

    private function attachRecentActivity(SeasonManager $seasonManager, Season $season): void
    {
        $activity = Activity::query()
            ->where('season_id', $season->id)
            ->where(fn ($query) => $query
                ->where('source_season_manager_id', $seasonManager->id)
                ->orWhere('target_season_manager_id', $seasonManager->id))
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

        $seasonManager->api_recent_activity = ActivityResource::collection($activity)->resolve();
    }
}
```

In `app/Http/Resources/ManagerResource.php`, make `toArray()` return:

```php
        return [
            'id' => $this->id,
            'url' => route('api.managers.show', $this->id),
            'name' => $this->name,
            'logo' => $this->logo ? asset($this->logo) : '',
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'rank' => $this->position,
            'last_rank' => $this->last_position,
            'total_points' => $this->total_points,
            'live_points' => $this->live_points,
            'squad_value' => $this->value,
            'daily_value_difference' => $this->daily_value_difference,
            'played_weeks' => $this->api_played_weeks,
            'average_points' => $this->api_average_points,
            'week_ranks' => $this->api_week_ranks,
            'current_lineup' => $this->api_current_lineup,
            'lineup_history' => $this->api_lineup_history,
            'roster' => $this->api_roster,
            'recent_activity' => $this->api_recent_activity,
        ];
```

In `app/Models/SeasonManager.php`:

Replace the `daily_value_difference` and `api_lineup_history` docblock lines with:

```php
 * @property int $daily_value_difference How much the manager's current squad gained or lost in the latest daily market update (sum of its players' daily value differences). Computed at query time by AttachesDailyValueDifference (HomeController, Api\ManagerController); not a database column.
 * @property array<int, array<string, mixed>> $api_lineup_history The manager's lineup for every finished jornada, oldest first. Computed at query time by Api\ManagerController; not a database column.
```

Add these lines after the `api_recent_activity` line:

```php
 * @property array<string, mixed>|null $api_current_lineup The lineup of the jornada being played or next to play (SeasonClock::lineupWeek), with live/final points and next starts; null without one. Computed at query time by Api\ManagerController; not a database column.
 * @property array<int, array{week_number: int, rank: int, managers: int, points: int, is_last: bool}> $api_week_ranks The manager's rank in each finished jornada he had a lineup for. Computed at query time by Api\ManagerController; not a database column.
 * @property int $api_played_weeks Finished jornadas the manager had a lineup for. Computed at query time by Api\ManagerController; not a database column.
 * @property float|null $api_average_points Average lineup points over those jornadas; null before any. Computed at query time by Api\ManagerController; not a database column.
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api tests/Feature/Http/Controllers/SeasonManagersControllerTest.php tests/Feature/Http/Controllers/HomeControllerTest.php`
Expected: PASS. The web ficha's `weekRanks`/`weeklySummary` and the home standings' `daily_value_difference` are unchanged.

- [ ] **Step 8: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Services/SeasonClock.php app/Services/ManagerWeekRanks.php app/Http/Controllers/SeasonManagersController.php app/Http/Controllers/Concerns/AttachesDailyValueDifference.php app/Http/Controllers/HomeController.php app/Http/Controllers/Api/ManagerController.php app/Http/Resources/ManagerResource.php app/Models/SeasonManager.php tests/Feature/Http/Controllers/Api/ManagerControllerTest.php
git commit -m "feat!: give the API manager ficha its current lineup, finished history, full roster and ranks" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 6: Per-jornada scoring breakdown on `/players/{id}`

`scores[]` already has `fixture_id`, `points` and `stats`. It is missing the minutes, the DAZN rating and the player's real match status (starter, subbed in/out). This task adds them so the AI can explain "why N points".

**Files:**
- Modify: `app/Http/Controllers/Api/PlayersController.php` (`attachScores()`, plus a new private `statPair()`)
- Modify: `app/Models/Player.php` (`api_scores` docblock)
- Test: `tests/Feature/Http/Controllers/Api/PlayerShowTest.php` (append two tests)

**Interfaces:**
- Consumes: `PlayersController` from Task 4.
- Produces: each `scores[]` entry gains `fixture_state`, `minutes: int|null`, `marca_points: int|null`, `starter: bool`, `subbed_in: bool`, `subbed_out: bool` and `sub_minute: int|null`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Http/Controllers/Api/PlayerShowTest.php`. It already imports `FixtureState`, `PlayerStatus`, `Fixture`, `FixtureLineup`, `Player`, `Season` and `Team`:

```php
test('breaks each jornada score down with minutes, starter, substitution and DAZN points', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'state' => FixtureState::Finished,
        'team_local_id' => $team->id,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $team->id,
        'starter' => true,
        'subbed_out' => true,
        'sub_minute' => 63,
        'fantasy_points' => 15,
        'fantasy_stats' => ['mins_played' => [63, 2], 'goals' => [1, 4], 'marca_points' => [-1, 4]],
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.scores.0.fixture_id', $fixture->id);
    $response->assertJsonPath('data.scores.0.fixture_state', 'finished');
    $response->assertJsonPath('data.scores.0.points', 15);
    $response->assertJsonPath('data.scores.0.minutes', 63);
    $response->assertJsonPath('data.scores.0.marca_points', 4);
    $response->assertJsonPath('data.scores.0.starter', true);
    $response->assertJsonPath('data.scores.0.subbed_in', false);
    $response->assertJsonPath('data.scores.0.subbed_out', true);
    $response->assertJsonPath('data.scores.0.sub_minute', 63);
    $response->assertJsonPath('data.scores.0.stats.goals', [1, 4]);
});

test('has null minutes and DAZN points when a score has no fantasy stats', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $team = Team::factory()->create();
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'team_local_id' => $team->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $team->id,
        'fantasy_stats' => null,
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    $response->assertJsonPath('data.scores.0.minutes', null);
    $response->assertJsonPath('data.scores.0.marca_points', null);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/PlayerShowTest.php`
Expected: FAIL. `minutes`, `marca_points`, `starter` and the other new keys are missing.

- [ ] **Step 3: Add the breakdown fields**

In `app/Http/Controllers/Api/PlayersController.php`, replace the `return [...]` inside `attachScores()`'s map closure with:

```php
                return [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'fixture_state' => $fixture->state->value,
                    'opponent' => (new TeamResource($isHome ? $fixture->guestTeam : $fixture->localTeam))->resolve(),
                    'is_home' => $isHome,
                    'points' => $lineup->fantasy_points,
                    'minutes' => $this->statPair($lineup->fantasy_stats, 'mins_played', 0),
                    'marca_points' => $this->statPair($lineup->fantasy_stats, 'marca_points', 1),
                    'starter' => $lineup->starter,
                    'subbed_in' => $lineup->subbed_in,
                    'subbed_out' => $lineup->subbed_out,
                    'sub_minute' => $lineup->sub_minute,
                    'stats' => $lineup->fantasy_stats,
                    'lineup_manager' => $seasonManager === null ? null : [
                        'id' => $seasonManager->id,
                        'name' => $seasonManager->name,
                    ],
                ];
```

and add this method after `attachScores()`:

```php
    /**
     * One number of a `[value, fantasy points]` stats pair. `$index` 0 is the
     * raw value (e.g. minutes played) and 1 the points it earned (e.g. the
     * DAZN rating's points). Null when the stat is missing.
     *
     * @param  array<string, mixed>|null  $stats
     */
    private function statPair(?array $stats, string $key, int $index): ?int
    {
        $pair = $stats[$key] ?? null;

        if (!is_array($pair) || !isset($pair[$index]) || !is_numeric($pair[$index])) {
            return null;
        }

        return (int) $pair[$index];
    }
```

In `app/Models/Player.php`, replace the `api_scores` docblock line with:

```php
 * @property array<int, array<string, mixed>> $api_scores One entry per fixture with a FixtureLineup for this player this season, oldest first: points, the `[value, points]` stats breakdown, minutes, DAZN points (`marca_points`), starter/sub facts and who fielded him. Computed at query time by Api\PlayersController; not a database column.
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/PlayerShowTest.php tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Http/Controllers/Api/PlayersController.php app/Models/Player.php tests/Feature/Http/Controllers/Api/PlayerShowTest.php
git commit -m "feat: break each API player score down with minutes, DAZN points and match status" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 7: `GET /api/teams`: the real LaLiga table with each team's next match and probable XI

**Files:**
- Create: `app/Http/Controllers/Api/TeamsController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Http/Controllers/Api/TeamsControllerTest.php`

**Interfaces:**
- Consumes:
  - `LeagueStandings::table(Collection<int, Team>, Collection<int, Fixture>, array = []): list<row>` and `LeagueStandings::fixtures(Season)` (existing).
  - `StartProbabilities::forTeamNextFixture(Team, Season): ?array` (existing).
- Produces: route `api.teams` → `GET /api/teams`. Each row has:
  - `rank` and `team{id,name,logo}`;
  - `played`, `won`, `drawn`, `lost`, `goals_for`, `goals_against`, `goal_difference`, `points`;
  - `recent_form[]{fixture_id, opponent, score, result, date}` and `live`;
  - `next_fixture{fixture_id, url, week_number, date, opponent, is_home, lineup}`, where `lineup` is `{source, confirmed, formation, is_stale, fetched_at, source_url, players[]{player{id,url,nickname,position}, probability, predicted_starter, confirmed_starter, pitch_position}}` or null.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/Controllers/Api/TeamsControllerTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;

/**
 * @return array{0: Season, 1: Team, 2: Team}
 */
function teamsApiLeague(): array
{
    $season = Season::factory()->create([
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(200),
        'current_week' => 2,
    ]);
    $barcelona = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $madrid = Team::factory()->create(['main_name' => 'Real Madrid']);
    $season->teams()->attach([$barcelona->id, $madrid->id]);

    return [$season, $barcelona, $madrid];
}

test('returns the real LaLiga table with each team\'s record and recent form', function (): void {
    [$season, $barcelona, $madrid] = teamsApiLeague();
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'state' => FixtureState::Finished,
        'team_local_id' => $barcelona->id,
        'team_guest_id' => $madrid->id,
        'local_score' => 2,
        'guest_score' => 1,
        'date' => now()->subDays(7),
    ]);

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.rank', 1);
    $response->assertJsonPath('data.0.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.0.played', 1);
    $response->assertJsonPath('data.0.won', 1);
    $response->assertJsonPath('data.0.points', 3);
    $response->assertJsonPath('data.0.goals_for', 2);
    $response->assertJsonPath('data.0.goals_against', 1);
    $response->assertJsonPath('data.0.goal_difference', 1);
    $response->assertJsonPath('data.0.recent_form.0.fixture_id', $fixture->id);
    $response->assertJsonPath('data.0.recent_form.0.opponent.name', 'Real Madrid');
    $response->assertJsonPath('data.0.recent_form.0.score', '2-1');
    $response->assertJsonPath('data.0.recent_form.0.result', 'win');
    $response->assertJsonPath('data.0.live', null);
    $response->assertJsonPath('data.1.rank', 2);
    $response->assertJsonPath('data.1.recent_form.0.result', 'loss');
});

test('adds each team\'s next match with its probable lineup from FútbolFantasy', function (): void {
    [$season, $barcelona, $madrid] = teamsApiLeague();
    $next = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $barcelona->id,
        'team_guest_id' => $madrid->id,
        'date' => now()->addDays(2),
    ]);
    $pedri = Player::factory()->create([
        'nickname' => 'Pedri',
        'status' => PlayerStatus::Ok,
        'team_id' => $barcelona->id,
        'position' => PlayerPosition::Midfield,
    ]);
    FixtureLineupProbability::factory()->onPitch(50, 40)->create([
        'fixture_id' => $next->id,
        'player_id' => $pedri->id,
        'probability' => 90,
    ]);

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonPath('data.0.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.0.next_fixture.fixture_id', $next->id);
    $response->assertJsonPath('data.0.next_fixture.week_number', 2);
    $response->assertJsonPath('data.0.next_fixture.is_home', true);
    $response->assertJsonPath('data.0.next_fixture.opponent.name', 'Real Madrid');
    $response->assertJsonPath('data.0.next_fixture.lineup.source', 'futbolfantasy');
    $response->assertJsonPath('data.0.next_fixture.lineup.confirmed', false);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.player.nickname', 'Pedri');
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.player.position', 'midfield');
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.probability', 90);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.predicted_starter', true);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.confirmed_starter', null);
    $response->assertJsonPath('data.1.next_fixture.is_home', false);
    $response->assertJsonPath('data.1.next_fixture.lineup', null);
});

test('uses the confirmed worldcup26 lineup once there is one', function (): void {
    [$season, $barcelona, $madrid] = teamsApiLeague();
    $next = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $barcelona->id,
        'team_guest_id' => $madrid->id,
        'date' => now()->addHours(1),
    ]);
    $pedri = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $barcelona->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $next->id,
        'player_id' => $pedri->id,
        'team_id' => $barcelona->id,
        'starter' => true,
        'position' => 'Center Midfielder',
    ]);

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_fixture.lineup.source', 'worldcup26');
    $response->assertJsonPath('data.0.next_fixture.lineup.confirmed', true);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.confirmed_starter', true);
    $response->assertJsonPath('data.0.next_fixture.lineup.players.0.probability', null);
});

test('has a null next fixture when the team has no match left', function (): void {
    teamsApiLeague();

    $response = $this->getJson('/api/teams');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_fixture', null);
});
```

- [ ] **Step 2: Run them to verify they fail**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/TeamsControllerTest.php`
Expected: FAIL with 404 (no `api/teams` route).

- [ ] **Step 3: Write the controller and route**

Create `app/Http/Controllers/Api/TeamsController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FixtureState;
use App\Enums\MatchResult;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use App\Services\LeagueStandings;
use App\Services\StartProbabilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The real LaLiga table (finished + live matches, like /equipos). For each
 * team it adds the next match and that match's probable XI (FútbolFantasy)
 * or confirmed XI (worldcup26 first), with the real formation and each
 * player's %.
 */
class TeamsController extends Controller
{
    public function __construct(
        private readonly LeagueStandings $standings,
        private readonly StartProbabilities $startProbabilities,
    ) {}

    public function index(): JsonResponse
    {
        $season = Season::current();
        $nextByTeam = $this->nextFixtureByTeam($season);
        $table = $this->standings->table($season->teams, $this->standings->fixtures($season));

        $data = array_map(fn (array $row): array => [
            'rank' => $row['position'],
            'team' => (new TeamResource($row['team']))->resolve(),
            'played' => $row['played'],
            'won' => $row['won'],
            'drawn' => $row['drawn'],
            'lost' => $row['lost'],
            'goals_for' => $row['goals_for'],
            'goals_against' => $row['goals_against'],
            'goal_difference' => $row['goal_difference'],
            'points' => $row['points'],
            'recent_form' => array_map(fn (array $entry): array => $this->presentResult($entry), $row['recent_form']),
            'live' => $row['live'] === null ? null : $this->presentResult($row['live']),
            'next_fixture' => $this->presentNextFixture($row['team'], $nextByTeam[$row['team']->id] ?? null, $season),
        ], $table);

        return response()->json(['data' => $data]);
    }

    /**
     * @param  array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: CarbonImmutable}  $entry
     * @return array{fixture_id: int, opponent: array<string, mixed>, score: string, result: string, date: string}
     */
    private function presentResult(array $entry): array
    {
        return [
            'fixture_id' => $entry['fixture_id'],
            'opponent' => (new TeamResource($entry['opponent']))->resolve(),
            'score' => $entry['score'],
            'result' => $entry['result']->value,
            'date' => $entry['date']->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentNextFixture(Team $team, ?Fixture $fixture, Season $season): ?array
    {
        if ($fixture === null) {
            return null;
        }

        $isHome = $fixture->team_local_id === $team->id;
        $block = $this->startProbabilities->forTeamNextFixture($team, $season);

        return [
            'fixture_id' => $fixture->id,
            'url' => route('api.fixtures.show', $fixture->id),
            'week_number' => $fixture->week_number,
            'date' => $fixture->date->toIso8601String(),
            'opponent' => (new TeamResource($isHome ? $fixture->guestTeam : $fixture->localTeam))->resolve(),
            'is_home' => $isHome,
            'lineup' => $block === null || $block['fixture_id'] !== $fixture->id ? null : [
                'source' => $block['confirmed_source'] ?? 'futbolfantasy',
                'confirmed' => $block['confirmed_source'] !== null,
                'formation' => $block['formation'],
                'is_stale' => $block['is_stale'],
                'fetched_at' => $block['fetched_at'],
                'source_url' => $block['source_url'],
                'players' => array_map(fn (array $entry): array => [
                    'player' => [
                        'id' => $entry['player']->id,
                        'url' => route('api.players.show', $entry['player']->id),
                        'nickname' => $entry['player']->nickname,
                        'position' => $entry['player']->position?->value,
                    ],
                    'probability' => $entry['probability'],
                    'predicted_starter' => $entry['predicted_starter'],
                    'confirmed_starter' => $entry['confirmed_starter'],
                    'pitch_position' => $entry['pitch_position'],
                ], $block['players']),
            ],
        ];
    }

    /**
     * Each team's next match: the soonest scheduled fixture still to come.
     * This is the same rule StartProbabilities uses, so the lineup belongs
     * to this match.
     *
     * @return array<int, Fixture>
     */
    private function nextFixtureByTeam(Season $season): array
    {
        $nextByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', now())
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get()
            ->each(function (Fixture $fixture) use (&$nextByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    $nextByTeam[$teamId] ??= $fixture;
                }
            });

        return $nextByTeam;
    }
}
```

In `routes/api.php`, add `use App\Http\Controllers\Api\TeamsController;` to the imports (alphabetical) and this route at the end:

```php
Route::get('teams', [TeamsController::class, 'index'])->name('api.teams');
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/TeamsControllerTest.php tests/Feature/Http/Controllers/Api/MaxBidGuardTest.php`
Expected: PASS. The guard test now also calls `/api/teams`.

- [ ] **Step 5: Format, analyse, commit**

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

```bash
git add app/Http/Controllers/Api/TeamsController.php routes/api.php tests/Feature/Http/Controllers/Api/TeamsControllerTest.php
git commit -m "feat: add GET /api/teams with the LaLiga table, next matches and probable XIs" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---
### Task 8: Rewrite `resources/docs/api-docs.md` for the AI advisor

**Files:**
- Modify (full rewrite): `resources/docs/api-docs.md`
- Test: `tests/Feature/Http/Controllers/ApiDocsControllerTest.php` (append one test)

**Interfaces:**
- Consumes: every field produced by Tasks 1–7, with exactly those names.
- Produces: a document the Task 9 drift test parses. The format below is load-bearing, so keep it exactly:
  - Each endpoint heading is `### GET /api/…` and sits inside `## 5. Referencia de endpoints`.
  - Each field table has the header `| Campo | Tipo | Significado |` and one path per row in the first cell, in backticks.
  - Paths are relative to `data`: `[]` means "every element of a list" and `roster[].player.id` is one path. Paths starting with `meta.` or `links.` are relative to the response root.
  - Parameter tables use the header `| Parámetro | Valores | Ejemplo |`.

- [ ] **Step 1: Write the failing content test**

Append to `tests/Feature/Http/Controllers/ApiDocsControllerTest.php`:

```php
test('guides the AI advisor through onboarding, rules and the endpoint reference', function (): void {
    $doc = (string) file_get_contents(resource_path('docs/api-docs.md'));

    expect($doc)
        ->toContain('## 1. Cómo usar esta API (instrucciones para la IA)')
        ->toContain('¿Qué manager eres?')
        ->toContain('Prefiero no decirlo')
        ->toContain('Dudas de puntuación')
        ->toContain('## 2. Manual del juego')
        ->toContain('5-4-1')
        ->toContain('## 3. Cómo leer las señales')
        ->toContain('## 4. Preguntas típicas')
        ->toContain('## 5. Referencia de endpoints')
        ->toContain('### GET /api/season')
        ->toContain('### GET /api/teams')
        ->toContain('## 6. Glosario')
        ->toContain('FútbolFantasy');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/ApiDocsControllerTest.php`
Expected: FAIL (the current doc has none of these sections).

- [ ] **Step 3: Replace the document**

Replace the whole content of `resources/docs/api-docs.md` with:

````markdown
# API de Comando Lechuga — guía para el asesor IA

Esta guía es para ti, la IA que va a aconsejar a un manager de **Comando Lechuga**, una liga privada de LaLiga Fantasy. Léela entera antes de contestar. Explica cómo trabajar con el usuario, las reglas del juego en esta liga, cómo leer los datos y qué devuelve cada endpoint.

- **Base URL:** `https://comandolechuga.com/api`. Solo lectura, JSON, sin autenticación ni API key: basta un `GET`.
- **Idioma de los datos:** las claves JSON y los valores de enumeración (`midfield`, `signing`, `finished`…) están en inglés y no cambian. Las etiquetas pensadas para personas (`type_label`, `state_label`, `label`) vienen en español.
- **Temporada:** todo se refiere a la temporada actual. No hay datos de temporadas pasadas.

---

## 1. Cómo usar esta API (instrucciones para la IA)

### 1.1 Reglas de trabajo

1. **Datos en vivo: no reutilices nunca una respuesta anterior.** Los datos se actualizan continuamente: el mercado cada pocos segundos; jugadores, alineaciones, clasificación y actividad cada minuto; las probabilidades de titularidad cada 10 minutos. Para **cada** pregunta nueva, vuelve a hacer las peticiones que necesites, aunque ya las hicieras antes en esta conversación. No memorices ni cachees respuestas.
2. **Lo último manda.** Cualquier dato puede cambiar a lo largo de la temporada, también la posición fantasy de un jugador (`position`). Usa siempre lo que diga la respuesta más reciente. No intentes deducir cómo ni por qué ha cambiado.
3. **Di de cuándo son los datos.** Todas las respuestas llevan `meta.generated_at` (fecha y hora con offset) y `meta.timezone` (`Europe/Madrid`). Cita la hora cuando des datos que cambian rápido: "según los datos de las 18:32".
4. **Horas en Madrid.** Todas las fechas vienen en ISO 8601 con offset: `+02:00` en horario de verano (CEST) y `+01:00` en invierno (CET). Las reglas de la liga (el mercado de las 20:00) están en hora de Madrid. Si el usuario vive en otra zona horaria, conviértelo, y ten en cuenta los cambios de hora.
5. **Dinero en euros enteros.** `45000000` son 45.000.000 €. Al contestar, redondea a algo legible: "45 M€", "12,3 M€", "850.000 €".
6. **Responde en el idioma del usuario** (por defecto, español).
7. **No inventes.** Si un dato no está en la API, dilo. La API **no** conoce:
   - el saldo (dinero en caja) de ningún manager;
   - las pujas de nadie (ni importes ni quién puja);
   - los jugadores que un manager ha puesto a la venta;
   - las ofertas recibidas;
   - las temporadas pasadas.

   Si una regla no está en esta guía, no la supongas: di "consúltalo en la app".
8. **Pregunta el saldo cuando haga falta.** Para aconsejar pujas o cláusulas necesitas el saldo actual del manager, y solo él lo sabe: pregúntaselo.
9. **La cifra de la oferta la razonas tú.** La API no da ninguna cifra de cuánto ofrecer. Razona tu propia oferta con los datos públicos (valor de mercado, tendencia, puntos, titularidad, calendario, número de pujas y el saldo que te diga el usuario) y explica el razonamiento.
10. **Cita a FútbolFantasy.** Las probabilidades de titularidad (`next_start` y las alineaciones probables de `/api/teams`) son de FútbolFantasy. Cuando las uses, dilo ("probabilidad según FútbolFantasy") y, si procede, enlaza `source_url`.
11. **Sirves a cualquier manager de la liga.** Todo lo que da la API es público para todos los managers. No tomes partido.
12. **Usa solo los parámetros documentados.** En `/api/players` y `/api/activity`, un parámetro desconocido o un valor no válido devuelve **422** con el nombre del parámetro en `errors`: corrige la petición. Los demás endpoints no tienen parámetros e ignoran la query string.
13. **Pide lo justo.** Haz solo las llamadas que la pregunta necesita (tabla del apartado 4). Usa los filtros (`/api/players?free=1&position=…&sort=…`) en lugar de recorrer todas las páginas de jugadores. Pedir lo justo no significa reutilizar: cada pregunta nueva, peticiones nuevas.

### 1.2 Presentación guiada (antes del primer consejo)

Muchos usuarios tienen poca experiencia con IAs. Antes de aconsejar, guíale con dos preguntas cortas, en este orden.

**Pregunta 1. "¿Qué manager eres?"**
Llama a `GET /api/standings` y muéstrale los managers (nombre y posición en la liga) para que elija uno. Añade la opción **"Prefiero no decirlo"**: en ese caso das consejos generales, sin su plantilla.

**Pregunta 2. "¿En qué quieres que te ayude?"** (puede elegir varias)

1. **Fichajes y pujas del mercado de hoy**: a quién fichar y cuánto ofrecer, razonado con los datos públicos.
2. **Ventas**: a quién vender, qué oferta de la liga aceptar y cuándo.
3. **Cláusulas**: a quién subir la cláusula propia, quién de su plantilla está expuesto, a quién clausular, si ya se puede y si compensa.
4. **Alineación de la jornada**: el once, una formación válida, titulares, dudas y hora de cierre.
5. **Análisis de mi plantilla**: puntos fuertes y débiles, quién no juega, qué reforzar y la evolución de valor.
6. **Seguimiento de la liga y rivales**: clasificación, plantillas y en qué gastan.
7. **Partidos y jornada en curso**: cómo va, puntos en vivo y quién juega ahora.
8. **Resumen rápido del día**: mercado, cambios de valor, alertas de titularidad y urgencias.
9. **Dudas de puntuación**: por qué un jugador sacó N puntos, o tal nota DAZN, en una jornada.

Después, trabaja cada intención elegida con las llamadas del apartado 4. Si el usuario pregunta algo fuera de la lista, contesta igualmente, con las mismas reglas.

**Dudas de puntuación, paso a paso:**
1. Pide `GET /api/players/{id}` y busca en `scores` la jornada (`week_number`).
2. Explica el desglose línea a línea con `stats`: cada entrada es `[valor, puntos]`. Usa la tabla del apartado 2.2.
3. Completa con los `events` de `GET /api/fixtures/{fixture_id}` (goles, tarjetas, VAR).
4. Para la nota DAZN (`marca_points`), explica sus componentes publicados (apartado 2.4) sin inventar cuánto vale cada acción: no tenemos todos los datos que usa DAZN.

---

## 2. Manual del juego

Estas son las reglas de **esta** liga. Lo que no aparezca aquí no lo afirmes: "consúltalo en la app".

### 2.1 Temporada y jornadas

- La temporada tiene 38 jornadas. Cada jornada agrupa los partidos de LaLiga de esa ronda, repartidos en varios días.
- Una jornada **empieza** con su primer partido y **termina** cuando acaba el último. `GET /api/season` dice cuál es la jornada actual y su estado: `not_started`, `live` o `finished`.
- Un partido aplazado (`state: postponed`) conserva su jornada, pero no cuenta para saber cuándo empieza la jornada.

### 2.2 Puntuación LaLiga Fantasy

Cada jugador suma puntos por lo que hace en su partido real.

Acciones que dependen de la posición en el campo:

| Acción | Portero | Defensa | Centrocampista | Delantero |
|---|---|---|---|---|
| Gol | 6 | 6 | 5 | 4 |
| Portería a cero (jugando más de 60 min) | 4 | 3 | 2 | 1 |
| Cada 2 goles encajados | −2 | −2 | −1 | −1 |
| Pérdidas de balón | −1 cada 8 | −1 cada 8 | −1 cada 10 | −1 cada 12 |

Acciones iguales para todos:

- Minutos jugados: menos de 60 → **1**; 60 o más → **2**.
- Asistencia de gol **3**; asistencia sin gol (ocasión clara) **1**.
- Penaltis: fallado **−2**, parado **+5**, provocado **+2**, cometido **−2**.
- Tarjetas: amarilla **−1**, doble amarilla **−1**, roja **−3**.
- Paradas: **+1** cada 2.
- Bonus de ataque: **+1** por cada 2 tiros a puerta, cada 2 regates logrados y cada 2 balones al área.
- Bonus defensivo: **+1** cada 5 balones recuperados, **+1** cada 3 despejes.
- **Nota DAZN** (`marca_points`): de 0 a 4 puntos, que se suman a todo lo anterior (apartado 2.4).

### 2.3 Cómo leer `stats`

En `/api/players/{id}` → `scores[].stats` y en `/api/fixtures/{id}` → `lineups[].stats`, cada clave es una acción y su valor es un par **`[valor, puntos]`**: la cifra real y los puntos fantasy que dio. Por ejemplo, `"goals": [1, 4]` es 1 gol que dio 4 puntos. `points` es la suma de los segundos números. Explica siempre con esos segundos números, sin recalcularlos. Si alguna vez no cuadran con `points`, fíate de `points`.

| Clave | Qué cuenta |
|---|---|
| `mins_played` | Minutos jugados |
| `goals` | Goles |
| `goal_assist` | Asistencias de gol |
| `offtarget_att_assist` | Asistencias sin gol (ocasión clara) |
| `pen_area_entries` | Balones al área |
| `penalty_won` | Penaltis provocados |
| `penalty_save` | Penaltis parados |
| `saves` | Paradas |
| `effective_clearance` | Despejes |
| `penalty_failed` | Penaltis fallados |
| `own_goals` | Goles en propia puerta |
| `goals_conceded` | Goles encajados. La portería a cero también aparece aquí: valor 0 y puntos positivos |
| `yellow_card` | Tarjetas amarillas |
| `second_yellow_card` | Doble amarilla |
| `red_card` | Tarjetas rojas |
| `total_scoring_att` | Tiros a puerta |
| `won_contest` | Regates logrados |
| `ball_recovery` | Balones recuperados |
| `poss_lost_all` | Pérdidas de balón |
| `penalty_conceded` | Penaltis cometidos |
| `marca_points` | Nota DAZN. Usa el segundo número (los puntos, 0–4); el primero es un valor interno del proveedor |

Ejemplo real: un delantero con `points: 15`.

| Línea | Par | Puntos |
|---|---|---|
| Minutos | `mins_played: [63, 2]` | 2 (más de 60 min) |
| Gol de delantero | `goals: [1, 4]` | 4 |
| Asistencia sin gol | `offtarget_att_assist: [1, 1]` | 1 |
| Balones al área | `pen_area_entries: [3, 1]` | 1 |
| Portería a cero de delantero | `goals_conceded: [0, 1]` | 1 |
| Tiros a puerta | `total_scoring_att: [2, 1]` | 1 |
| Regates | `won_contest: [3, 1]` | 1 |
| Nota DAZN | `marca_points: [-1, 4]` | 4 |
| **Total** | | **15** |

En `/api/fixtures/{id}`, un jugador que no está vinculado a la Fantasy solo trae 6 claves (`goals`, `own_goals`, `goal_assist`, `yellow_card`, `red_card`, `goals_conceded`), todas con 0 puntos. Son datos del partido, no puntuación.

### 2.4 Nota DAZN

`marca_points` es la nota DAZN: estadísticas avanzadas (OPTA) agrupadas en cinco bloques (**portería, defensivas, distribución, ofensivas y negativas**), ponderadas según los minutos jugados. El resultado es una nota de **0 a 4 puntos** que se suma a los demás puntos. Si te preguntan por una nota concreta, explica qué tipo de acciones la suben o la bajan según esos bloques y el tiempo jugado. No inventes una fórmula por estadística: no tenemos todos los datos que usa DAZN.

### 2.5 Plantilla y alineación

- Máximo **24 jugadores** por plantilla.
- La alineación es un **once con 1 portero** en una de estas formaciones (`position` de cada jugador: `goalkeeper` = portero, `defender` = defensa, `midfield` = centrocampista, `striker` = delantero):

| Formación | Porteros | Defensas | Centrocampistas | Delanteros |
|---|---|---|---|---|
| 3-4-3 | 1 | 3 | 4 | 3 |
| 3-5-2 | 1 | 3 | 5 | 2 |
| 4-3-3 | 1 | 4 | 3 | 3 |
| 4-4-2 | 1 | 4 | 4 | 2 |
| 4-5-1 | 1 | 4 | 5 | 1 |
| 5-3-2 | 1 | 5 | 3 | 2 |
| 5-4-1 | 1 | 5 | 4 | 1 |

  Antes de proponer un once, comprueba que encaja exactamente en una de ellas.
- **No confundas** la formación fantasy con la formación real de un equipo de LaLiga (`/api/teams` → `next_fixture.lineup.formation`, `/api/fixtures/{id}` → `local_formation`). La real describe cómo juega su club, no el once del manager.
- **Cierre:** la alineación se bloquea cuando empieza el primer partido de la jornada (`/api/season` → `upcoming_week.lineup_locks_at`). Solo cuenta el once guardado antes.
- **Si al empezar la jornada la alineación no está completa, el manager no puntúa esa jornada.** Avisa con tiempo si falta alguien, si hay lesionados (`status: injured`), sancionados (`suspended`) o dudas (`doubtful`) en el once, o si la plantilla no llega a 11 jugadores disponibles.
- Una vez ha empezado la jornada, ya se pueden volver a vender jugadores.

### 2.6 Saldo

- **No se puede empezar una jornada con saldo negativo:** ese manager puntúa 0 en esa jornada.
- Comprar en el mercado puede dejar el saldo en negativo, **hasta −20 % del valor del equipo** (`squad_value`).
- Una **cláusula se paga con dinero propio** y nunca puede dejar el saldo en negativo.
- La API no tiene el saldo: pregúntaselo al usuario antes de aconsejar pujas o cláusulas.

### 2.7 Mercado de esta liga

- El mercado **se renueva cada día a las 20:00** (hora de Madrid, la hora a la que se creó esta liga). `/api/season` → `next_market_renewal_at` dice cuándo es la próxima renovación.
- Los jugadores que saca la liga están **24 horas** en el mercado. Gana la **puja más alta**.
- Las pujas son **ciegas**: solo se ve cuántas hay (`bids`), nunca los importes ni quién ha pujado. En caso de empate, gana la **primera** puja.
- La API tampoco sabe quién ha pujado ni cuánto. No afirmes nada sobre las pujas de otros managers.
- `GET /api/market` solo contiene lo que saca la liga (`seller: "league"`). Si está vacío, di cuándo se renueva (`/api/season`).

### 2.8 Vender

Hay dos formas de vender a un jugador propio:

1. **Ponerlo en el mercado.** Está allí 3 días. Cada día a las 20:00 la liga hace una oferta de entre −10 % y +10 % de su valor de mercado, que el manager acepta o rechaza. Mientras tanto, otros managers también pueden pujar por él.
2. **Venta inmediata a la liga:** al instante, por el **50 %** de su valor de mercado.

### 2.9 Dinero

- Cada manager empieza con **100 M€ y 14 jugadores al azar**.
- Gana **100.000 € por cada punto** que suma su once, cobrados al final de la jornada, además de lo que ingrese por ventas.
- En la actividad, `weekly_prize` ("Premio semanal") es ese cobro de la jornada. Lo reciben **todos** los managers cada jornada, así que **no** indica quién ganó la jornada.

### 2.10 Cláusulas

- Todo jugador de una plantilla tiene una **cláusula** (`/api/managers/{id}` → `roster[].buyout_clause.amount`). Otro manager puede pagarla y llevárselo sin permiso del dueño; en la actividad sale como `buyout` ("Cláusula").
- **Bloqueo de 14 días** tras comprarlo: no se le puede clausular hasta `buyout_clause.locked_until` (`is_locked: true` mientras dure).
- La cláusula vale **el mayor de su valor de mercado y lo que se pagó por él**. `amount` ya es la cifra vigente.
- **Ventana cerrada:** no se pueden pagar cláusulas desde **24 horas antes del primer partido de la jornada hasta que empieza**; después se vuelve a poder. Antes de recomendar un clausulazo, mira `/api/season` → `buyouts_open` y `upcoming_week.buyouts_close_at`.
- **Subir la cláusula de un jugador propio** la sube el **doble de lo invertido**: invertir 500.000 € la sube 1 M€.
- `shielded: true` (hasta `shielded_until`) es la marca de blindaje de la app. Si la ves, no recomiendes clausularlo sin que el usuario lo confirme en la app.

### 2.11 Lo que esta liga no tiene

Esta liga **no** tiene capitán, banquillo ni suplentes automáticos, hueco de entrenador ni cesiones. No los propongas. Los entrenadores no aparecen en la API.

### 2.12 Reglas que no afirmamos

No están confirmadas para esta liga, así que no las afirmes: el máximo de jugadores de un mismo equipo real, la plantilla mínima y cualquier otra regla que no esté en esta guía. Di "consúltalo en la app".

---

## 3. Cómo leer las señales

Son datos para razonar, no recetas. Combínalos con la pregunta del usuario y explica siempre qué dato pesa en tu consejo.

### 3.1 Tendencia de mercado (`market_trend`)

Compara el ritmo de subida o bajada del valor de mercado de los últimos 3 días con el de los 3 anteriores. Siempre coincide con el signo del último cambio diario (`market_value_difference`). Es `null` si hay menos de 7 días de historial o el valor está plano.

| Valor | Lectura |
|---|---|
| `rise_accelerating_sharply` | Sube, y mucho más rápido que antes |
| `rise_accelerating` | Sube, cada vez más rápido |
| `rise_steady` | Sube a ritmo constante |
| `rise_decelerating` | Sube, pero se frena |
| `rise_decelerating_sharply` | Sube muy poco o casi se ha parado: posible techo |
| `positive_inflection` | Venía bajando y hoy ha subido: cambio de tendencia por confirmar |
| `negative_inflection` | Venía subiendo y hoy ha bajado: señal de alerta si lo tienes |
| `fall_decelerating_sharply` | Baja muy poco o casi se ha parado: posible suelo |
| `fall_decelerating` | Baja, pero se frena |
| `fall_steady` | Baja a ritmo constante |
| `fall_accelerating` | Baja, cada vez más rápido |
| `fall_accelerating_sharply` | Baja, y mucho más rápido que antes |

### 3.2 Titularidad (`next_start`)

`next_start` dice si el jugador será titular en el **próximo partido de su equipo** (`fixture_id`, `opponent`, `is_home`, `date`).

- `probability` (0–100, de FútbolFantasy):
  - **90 o más**: titular casi seguro;
  - **70–89**: probable;
  - **menos de 70**: duda o rotación.
- `predicted_starter: true`: está en el once probable de FútbolFantasy.
- `confirmed_starter` manda sobre la probabilidad. `true` o `false` significa que la alineación ya está confirmada; `null`, que aún no lo está.
- `source` dice de dónde sale el dato:
  - `worldcup26`: la alineación oficial, publicada unos 90 minutos antes del partido;
  - `futbolfantasy`: la probabilidad, o su "alineación confirmada".

  Si las dos fuentes no coinciden, gana `worldcup26`.
- `is_stale: true` significa que el dato tiene más de 48 horas. Dilo al usarlo.
- `fetched_at` es cuándo se obtuvo el dato.
- `next_start: null` significa **sin datos**, nunca 0 %. Pasa, por ejemplo, con un partido aplazado, un jugador que FútbolFantasy no lista o un jugador fuera de la liga. No lo adivines.
- Si el jugador ha cambiado de club, todo se refiere a su equipo actual.

### 3.3 Dificultad del rival (`next_fixtures[].difficulty`)

Es la posición del rival en la tabla real (`rival_position`), escalada de **−1** (líder: el partido más difícil) a **+1** (colista: el más fácil). Vale `null` si el rival no está en la tabla.

### 3.4 Formación real y papel en el campo

`/api/teams` → `next_fixture.lineup` da el once probable o confirmado de cada club: la `formation` real (p. ej. `"4-3-3"`) y el `pitch_position` de cada titular (vocabulario en inglés, como `"Right Back"` o `"Center Left Midfielder"`). Sirve para ver con quién compite un jugador por el puesto. No es la formación fantasy del manager.

### 3.5 Métricas de valor

- `value_trend_30d.multiple`: el valor de mercado actual dividido entre el de hace unos 30 días (`value_trend_30d.value`, `value_trend_30d.date`). `1.5` es +50 %.
- `points_per_million.value`: los puntos de la temporada por cada millón de valor de mercado. `points_per_million.rank` es su puesto entre los `ranked` jugadores con puntos (1 = el más rentable).
- `owner_gain`: el valor de mercado actual menos lo que pagó su dueño actual (`paid`) en su último fichaje o cláusula (`type`, `occurred_at`). Vale `null` si está libre o si el dueño no tiene una compra registrada.

### 3.6 Señales de un manager

En `/api/managers/{id}`:
- `week_ranks`: su puesto en cada jornada terminada.
- `average_points`: su media por jornada, contando solo las jornadas en que tuvo alineación.
- `daily_value_difference`: cuánto ganó o perdió su plantilla en la última actualización diaria de valores.
- `live_points`: sus puntos provisionales mientras la jornada está en juego.

---

## 4. Preguntas típicas → qué consultar

Haz estas llamadas **cada vez** que llegue la pregunta.

| Intención | Llamadas | Qué mirar |
|---|---|---|
| 1. Fichajes y pujas | `/api/season`, `/api/market`, `/api/managers/{id}` (su plantilla) y `/api/players/{id}` de cada candidato | `next_start`, `market_trend`, `points_per_million`, `next_fixtures[].difficulty`, `bids`, `expires_at`; qué posición le falta; su saldo (pregúntalo) |
| 2. Ventas | `/api/season`, `/api/managers/{id}` y `/api/players/{id}` de los candidatos | `market_trend`, `value_trend_30d`, `next_start`, `status`, `owner_gain`; la oferta de la liga es de ±10 % a las 20:00 |
| 3. Cláusulas | `/api/season` (`buyouts_open`), `/api/managers/{id}` propio y de rivales | `buyout_clause.amount`, `is_locked`, `locked_until`, `shielded`; su saldo, sin quedar en negativo |
| 4. Alineación | `/api/season` (`upcoming_week.lineup_locks_at`), `/api/managers/{id}` (`roster`, `current_lineup`) y `/api/teams` | `next_start`, `status`, una formación válida (2.5), `difficulty` |
| 5. Análisis de plantilla | `/api/managers/{id}` y `/api/players?manager={id}&sort=points_per_million` | posiciones cubiertas, quién no juega, `market_trend`, `value_trend_30d` |
| 6. Liga y rivales | `/api/standings`, `/api/managers/{id}` de cada rival y `/api/activity?manager={id}` | `rank`, `week_ranks`, `average_points`, fichajes y cláusulas recientes |
| 7. Jornada en curso | `/api/season`, `/api/managers/{id}` (`current_lineup`) y `/api/fixtures/{id}` de los partidos en juego | `live_points`, `current_lineup.players[].points`, `match.state`, `display_clock`, `events` |
| 8. Resumen del día | `/api/season`, `/api/market` y `/api/managers/{id}` | la renovación de las 20:00, `daily_value_difference`, alertas de `status`/`next_start`, cierre de alineación y cláusulas |
| 9. Dudas de puntuación | `/api/players/{id}` y `/api/fixtures/{fixture_id}` | `scores[].stats`, `minutes`, `marca_points`, `events` |

Atajos útiles:

- Los que más suben hoy: `/api/players?sort=difference`.
- Gangas libres: `/api/players?free=1&sort=points_per_million`.
- Titulares seguros y baratos: `/api/players?free=1&min_start_probability=90&max_value=15000000`.
- Actividad reciente de un rival: `/api/activity?manager={id}`.

`/api/fixtures` es un calendario grande (38 jornadas). Para saber "qué pasa ahora", empieza por `/api/season`.

---

## 5. Referencia de endpoints

### 5.0 Convenciones

- **Forma:** `{"data": …, "meta": {…}}`. `data` es un objeto en las fichas y una lista en los listados. `meta` siempre trae `generated_at` y `timezone`.
- **Paginación:** solo `/api/players` (15 por página) y `/api/activity` (30). Pide más con `?page=2`, combinable con los filtros. `links.next` ya lleva los filtros puestos, y `meta` añade `current_page`, `last_page`, `per_page` y `total`.
- **Imágenes** (`logo`, `image`): URL absolutas. Sin imagen, cadena vacía `""`, nunca `null`.
- **`url`:** managers, partidos y jugadores traen la URL absoluta de su endpoint, lista para pedirla.
- **IDs:** internos de Comando Lechuga. Úsalos para enlazar recursos.
- **Errores:**
  - **404** si el id no existe: `{"message": "…"}`.
  - **422** si un filtro de `/api/players` o `/api/activity` es desconocido o no válido: `{"message": "…", "errors": {"<parámetro>": ["…"]}}`.
- **Jugadores excluidos:** los listados nunca incluyen jugadores fuera de la liga (`out_of_league`) ni entrenadores. `/api/players/{id}` sí puede devolver un jugador `out_of_league`, que ya no puntúa.

### GET /api/season

Qué pasa ahora: la jornada actual y su estado, la próxima jornada con su cierre de alineación y la ventana de cláusulas, el próximo partido y la próxima renovación del mercado. Empieza casi cualquier consejo por aquí. Sin parámetros.

```json
{
  "data": {
    "name": "LaLiga 26/27",
    "start_date": "2026-08-14",
    "end_date": "2027-05-30",
    "total_weeks": 38,
    "current_week": 8,
    "current_week_state": "not_started",
    "upcoming_week": {
      "week_number": 8,
      "lineup_locks_at": "2026-10-02T21:00:00+02:00",
      "buyouts_close_at": "2026-10-01T21:00:00+02:00",
      "buyouts_reopen_at": "2026-10-02T21:00:00+02:00"
    },
    "buyouts_open": true,
    "next_fixture": { "id": 71, "week_number": 8, "date": "2026-10-02T21:00:00+02:00", "state": "scheduled", "...": "misma forma que en /api/fixtures" },
    "next_market_renewal_at": "2026-09-28T20:00:00+02:00"
  },
  "meta": { "generated_at": "2026-09-28T18:32:05+02:00", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `name` | texto | Nombre de la temporada. |
| `start_date` | fecha | Inicio de la temporada (`AAAA-MM-DD`). |
| `end_date` | fecha | Fin de la temporada. |
| `total_weeks` | entero | Número de jornadas (38). |
| `current_week` | entero | Jornada actual. |
| `current_week_state` | texto | `not_started` (ningún partido ha empezado), `live` (empezada y sin terminar) o `finished`. Los aplazados no cuentan. |
| `upcoming_week` | objeto o null | La próxima jornada cuya alineación aún se puede guardar: la actual si no ha empezado, si no la siguiente. `null` tras la última jornada o si no tiene partidos. |
| `upcoming_week.week_number` | entero | Número de esa jornada. |
| `upcoming_week.lineup_locks_at` | fecha y hora | Cierre de alineación: el primer partido programado de la jornada. |
| `upcoming_week.buyouts_close_at` | fecha y hora | Desde aquí (24 h antes del cierre) no se pueden pagar cláusulas. |
| `upcoming_week.buyouts_reopen_at` | fecha y hora | Las cláusulas se reabren al empezar la jornada. |
| `buyouts_open` | booleano | Si ahora mismo se pueden pagar cláusulas. |
| `next_fixture` | objeto o null | El próximo partido que aún no ha empezado, de cualquier jornada. Misma forma que un partido de `/api/fixtures`. |
| `next_fixture.id` | entero | Id del partido, para `/api/fixtures/{id}`. |
| `next_fixture.week_number` | entero | Jornada del partido. |
| `next_fixture.date` | fecha y hora | Hora de inicio. |
| `next_fixture.local_team` | objeto | Equipo local `{id, name, logo}`. |
| `next_fixture.guest_team` | objeto | Equipo visitante. |
| `next_market_renewal_at` | fecha y hora | Próxima renovación del mercado (20:00 en Madrid). |
| `meta.generated_at` | fecha y hora | Cuándo se generó esta respuesta. Está en todas las respuestas. |
| `meta.timezone` | texto | `Europe/Madrid`. Está en todas las respuestas. |

### GET /api/standings

Clasificación de la liga, por posición. Sin parámetros ni paginación. Da los `id` de manager para `/api/managers/{id}` y para los filtros `manager=`.

```json
{
  "data": [
    {
      "id": 4, "url": "https://comandolechuga.com/api/managers/4", "name": "CID F.C",
      "logo": "https://comandolechuga.com/images/managers/37394771.png",
      "primary_color": "#3d7dfd", "secondary_color": "#0a0a0a",
      "rank": 2, "last_rank": 2, "total_points": 355, "squad_value": 167256574,
      "recent_form": [ { "week_number": 6, "points": 51, "live": false }, { "week_number": 7, "points": 44, "live": false } ]
    }
  ]
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].id` | entero | Id del manager. |
| `[].url` | texto | URL de su ficha en la API. |
| `[].name` | texto | Nombre del equipo del manager. |
| `[].logo` | texto | Escudo (URL o `""`). |
| `[].primary_color` | texto o null | Color principal (hex). |
| `[].secondary_color` | texto o null | Color secundario (hex). |
| `[].rank` | entero | Posición en la liga. |
| `[].last_rank` | entero | Posición al cierre de la jornada anterior. |
| `[].total_points` | entero | Puntos de la temporada (sin la jornada en juego). |
| `[].squad_value` | entero (€) | Valor de mercado de su plantilla. **No** es su saldo. |
| `[].recent_form` | lista | Hasta 3 jornadas, de la más antigua a la más reciente, sin relleno. Con una jornada en juego, la última entrada es esa jornada con `live: true` y puntos provisionales. |
| `[].recent_form[].week_number` | entero | Jornada. |
| `[].recent_form[].points` | entero o null | Puntos en esa jornada. |
| `[].recent_form[].live` | booleano | Si es la jornada en juego. |

### GET /api/managers/{id}

La ficha de un manager: su forma, su alineación de la jornada, sus jornadas terminadas, su plantilla completa y su actividad reciente. `{id}` sale de `/api/standings`.

```json
{
  "data": {
    "id": 4, "name": "CID F.C", "rank": 2, "last_rank": 2, "total_points": 355,
    "live_points": null, "squad_value": 167256574, "daily_value_difference": 1250000,
    "played_weeks": 7, "average_points": 50.71,
    "week_ranks": [ { "week_number": 7, "rank": 1, "managers": 7, "points": 68, "is_last": false } ],
    "current_lineup": {
      "week_number": 8, "week_state": "not_started", "formation": "4-5-1", "tactical_formation": [4, 5, 1],
      "lineup_locks_at": "2026-10-02T21:00:00+02:00", "points": 0,
      "players": [
        {
          "player": { "id": 30, "nickname": "M. Dituro", "status": "ok", "position": "goalkeeper", "...": "..." },
          "position": "goalkeeper",
          "match": { "fixture_id": 75, "state": "scheduled", "date": "2026-10-03T16:15:00+02:00", "display_clock": null, "...": "..." },
          "points": null, "match_finished": false,
          "next_start": { "probability": 95, "source": "futbolfantasy", "...": "misma forma que en /api/players" }
        }
      ]
    },
    "lineup_history": [ { "week_number": 7, "points": 68, "formation": "4-4-2", "tactical_formation": [4, 4, 2], "players": [ "..." ] } ],
    "roster": [
      {
        "player": { "id": 30, "nickname": "M. Dituro", "...": "misma forma que en /api/players" },
        "purchase": { "amount": 9500000, "type": "signing", "occurred_at": "2026-09-17T20:00:56+02:00" },
        "buyout_clause": { "amount": 9791531, "locked_until": "2026-10-01T20:00:56+02:00", "is_locked": true, "shielded": false, "shielded_until": null }
      }
    ],
    "recent_activity": [ "misma forma que /api/activity" ]
  }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `id` | entero | Id del manager. |
| `url` | texto | URL de esta ficha. |
| `name` | texto | Nombre del equipo del manager. |
| `logo` | texto | Escudo (URL o `""`). |
| `primary_color` | texto o null | Color principal. |
| `secondary_color` | texto o null | Color secundario. |
| `rank` | entero | Posición en la liga. |
| `last_rank` | entero | Posición al cierre de la jornada anterior. |
| `total_points` | entero | Puntos de la temporada (sin la jornada en juego). |
| `live_points` | entero o null | Puntos provisionales de la jornada en juego; `null` si no hay jornada en juego. |
| `squad_value` | entero (€) | Valor de mercado de su plantilla (no es saldo). |
| `daily_value_difference` | entero (€) | Cuánto ganó o perdió su plantilla en la última actualización diaria de valores. |
| `played_weeks` | entero | Jornadas terminadas en las que tuvo alineación. |
| `average_points` | número o null | Media de puntos por jornada, solo sobre esas jornadas. |
| `week_ranks` | lista | Su puesto en cada jornada terminada con alineación, de la más antigua a la más reciente. |
| `week_ranks[].week_number` | entero | Jornada. |
| `week_ranks[].rank` | entero | Puesto en esa jornada (los empates comparten el mejor puesto). |
| `week_ranks[].managers` | entero | Managers con alineación esa jornada. |
| `week_ranks[].points` | entero | Sus puntos esa jornada. |
| `week_ranks[].is_last` | booleano | Si fue último (o empató en el último puesto). |
| `current_lineup` | objeto o null | La alineación de la jornada en juego o de la próxima: la actual hasta que termina, luego la siguiente. `null` si no tiene; avisa antes de `lineup_locks_at`. |
| `current_lineup.week_number` | entero | Jornada. |
| `current_lineup.week_state` | texto | `not_started`, `live` o `finished`. |
| `current_lineup.formation` | texto | Formación fantasy, p. ej. `"4-4-2"`. |
| `current_lineup.tactical_formation` | lista | La misma formación como `[defensas, centrocampistas, delanteros]`. |
| `current_lineup.lineup_locks_at` | fecha y hora o null | Cierre de la alineación (primer partido de la jornada). |
| `current_lineup.points` | entero | Puntos de la alineación según la última sincronización. |
| `current_lineup.players` | lista | Los jugadores alineados. |
| `current_lineup.players[].player` | objeto | `{id, url, nickname, image, status, position, team}`. |
| `current_lineup.players[].player.status` | texto | `ok`, `injured`, `doubtful`, `suspended` u `out_of_league`. |
| `current_lineup.players[].position` | texto | Posición en el campo en la que está alineado. |
| `current_lineup.players[].match` | objeto o null | Su partido de esa jornada: `{fixture_id, url, state, date, display_clock}`. |
| `current_lineup.players[].match.state` | texto | Estado del partido (ver `/api/fixtures`). |
| `current_lineup.players[].points` | entero o null | Puntos en vivo mientras su partido se juega y finales cuando acaba; `null` antes de empezar o si no llegó a jugar. |
| `current_lineup.players[].match_finished` | booleano | Si el partido de su equipo en esa jornada ya terminó. |
| `current_lineup.players[].next_start` | objeto o null | Su titularidad para ese partido, solo si aún no ha empezado (forma de `/api/players`). |
| `current_lineup.players[].next_start.probability` | entero o null | Probabilidad de titularidad (FútbolFantasy). |
| `lineup_history` | lista | Sus alineaciones de las jornadas **terminadas**, de la más antigua a la más reciente. |
| `lineup_history[].week_number` | entero | Jornada. |
| `lineup_history[].points` | entero | Puntos de esa alineación. |
| `lineup_history[].formation` | texto | Formación fantasy. |
| `lineup_history[].tactical_formation` | lista | La misma formación como lista. |
| `lineup_history[].players[].player.id` | entero | Id del jugador alineado. |
| `lineup_history[].players[].position` | texto | Posición en la que se alineó. |
| `lineup_history[].players[].points` | entero o null | Sus puntos; `null` si no jugó. |
| `lineup_history[].players[].match_finished` | booleano | Si su partido había terminado. Con `points: null` significa que no llegó a jugar. |
| `roster` | lista | La plantilla completa actual. |
| `roster[].player` | objeto | El jugador, con la forma completa de `/api/players`. |
| `roster[].player.next_start` | objeto o null | Titularidad en su próximo partido. |
| `roster[].player.owner_gain` | objeto o null | Plusvalía sobre lo que pagó. |
| `roster[].purchase` | objeto o null | Su último fichaje o cláusula de este jugador; `null` si lo tenía de antes o no consta. |
| `roster[].purchase.amount` | entero (€) | Lo que pagó. |
| `roster[].purchase.type` | texto | `signing` o `buyout`. |
| `roster[].purchase.occurred_at` | fecha y hora | Cuándo. |
| `roster[].buyout_clause.amount` | entero (€) | Cláusula vigente. |
| `roster[].buyout_clause.locked_until` | fecha y hora | Fin del bloqueo de 14 días. |
| `roster[].buyout_clause.is_locked` | booleano | Si ahora mismo está bloqueada. |
| `roster[].buyout_clause.shielded` | booleano | Marca de blindaje de la app. |
| `roster[].buyout_clause.shielded_until` | fecha y hora o null | Hasta cuándo. |
| `recent_activity` | lista | Sus 10 últimos movimientos como origen o destino (forma de `/api/activity`). |
| `recent_activity[].type` | texto | Tipo de movimiento. |

### GET /api/players

Jugadores de la liga, filtrables y ordenables. 15 por página.

| Parámetro | Valores | Ejemplo |
|---|---|---|
| `position` | Una o varias de `goalkeeper`, `defender`, `midfield`, `striker`, separadas por comas | `?position=defender,midfield` |
| `team` | Ids de equipo real (de `/api/teams`), separados por comas | `?team=21,22` |
| `manager` | Ids de manager dueño (de `/api/standings`), separados por comas | `?manager=4` |
| `status` | Uno o varios de `ok`, `injured`, `doubtful`, `suspended` | `?status=injured,doubtful` |
| `search` | Texto; busca en el apodo sin distinguir mayúsculas ni acentos | `?search=valentin` |
| `free` | `1`/`true`: solo libres; `0`/`false`: solo con dueño | `?free=1` |
| `min_value` | Valor de mercado mínimo (€) | `?min_value=5000000` |
| `max_value` | Valor de mercado máximo (€) | `?max_value=15000000` |
| `min_start_probability` | 0–100: probabilidad mínima de FútbolFantasy para su próximo partido. No tiene en cuenta las alineaciones ya confirmadas; mira `next_start.confirmed_starter` | `?min_start_probability=70` |
| `sort` | `points` (por defecto), `value`, `difference` (cambio de valor del último día), `trend` (tendencia de mercado, de la subida más fuerte a la bajada más fuerte), `points_per_million` | `?sort=trend` |
| `direction` | `desc` (por defecto) o `asc` | `?direction=asc` |
| `page` | Página, desde 1 | `?page=2` |

Un parámetro desconocido o un valor no válido devuelve **422** (p. ej. `position=coach` o `status=out_of_league`: los entrenadores y los jugadores fuera de la liga nunca aparecen).

```json
{
  "data": [
    {
      "id": 646, "url": "https://comandolechuga.com/api/players/646", "nickname": "Raphinha",
      "image": "https://comandolechuga.com/storage/images/player/2522.png",
      "status": "ok", "position": "striker",
      "team": { "id": 39, "name": "FC Barcelona", "logo": "https://comandolechuga.com/storage/images/team/4.png" },
      "market_value": 169996294, "market_value_difference": 2870579, "market_trend": "rise_steady",
      "points": 117, "average_points": 16.71, "owner_manager": null,
      "recent_scores": [ { "week_number": 7, "opponent": { "id": 30, "name": "Elche CF", "logo": "…" }, "points": 24 } ],
      "next_fixtures": [ { "fixture_id": 75, "week_number": 8, "date": "2026-10-04T21:00:00+02:00", "opponent": { "id": 24, "name": "Rayo Vallecano", "logo": "…" }, "is_home": true, "rival_position": 14, "difficulty": 0.368 } ],
      "next_start": { "fixture_id": 75, "week_number": 8, "date": "2026-10-04T21:00:00+02:00", "opponent": { "id": 24, "name": "Rayo Vallecano", "logo": "…" }, "is_home": true, "probability": 95, "predicted_starter": true, "confirmed_starter": null, "source": "futbolfantasy", "is_stale": false, "fetched_at": "2026-09-28T17:50:00+02:00", "source_url": "https://www.futbolfantasy.com/laliga/equipos/barcelona" },
      "value_trend_30d": { "multiple": 1.42, "value": 119716000, "date": "2026-08-29" },
      "points_per_million": { "value": 0.69, "rank": 41, "ranked": 402 },
      "owner_gain": null
    }
  ],
  "links": { "next": "https://comandolechuga.com/api/players?sort=trend&page=2", "...": "..." },
  "meta": { "current_page": 1, "last_page": 38, "per_page": 15, "total": 556, "generated_at": "…", "timezone": "Europe/Madrid" }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].id` | entero | Id del jugador. |
| `[].url` | texto | URL de su ficha. |
| `[].nickname` | texto | Apodo. |
| `[].image` | texto | Foto (URL o `""`). |
| `[].status` | texto | `ok`, `injured`, `doubtful` o `suspended`. |
| `[].position` | texto | Posición en el campo: `goalkeeper`, `defender`, `midfield` o `striker`. Puede cambiar durante la temporada: usa la de esta respuesta. |
| `[].team` | objeto | Su equipo real actual. |
| `[].team.id` | entero | Id del equipo. |
| `[].team.name` | texto | Nombre del equipo. |
| `[].team.logo` | texto | Escudo del equipo. |
| `[].market_value` | entero (€) | Valor de mercado actual. |
| `[].market_value_difference` | entero (€) | Cambio del valor de mercado en la última actualización diaria. |
| `[].market_trend` | texto o null | Tendencia de mercado (apartado 3.1). |
| `[].points` | entero | Puntos de la temporada. |
| `[].average_points` | número | Media de puntos por partido. |
| `[].owner_manager` | objeto o null | Su dueño `{id, name, logo, primary_color}`; `null` = libre. |
| `[].owner_manager.id` | entero | Id del manager dueño. |
| `[].recent_scores` | lista | Hasta 3 últimos partidos terminados de su equipo, del más antiguo al más reciente. |
| `[].recent_scores[].week_number` | entero | Jornada. |
| `[].recent_scores[].opponent` | objeto | Rival. |
| `[].recent_scores[].points` | entero o null | Sus puntos; `null` si no jugó aunque su equipo sí. |
| `[].next_fixtures` | lista | Hasta 3 próximos partidos programados de su equipo, del más cercano al más lejano. |
| `[].next_fixtures[].fixture_id` | entero | Id del partido. |
| `[].next_fixtures[].week_number` | entero | Jornada. |
| `[].next_fixtures[].date` | fecha y hora | Hora de inicio. |
| `[].next_fixtures[].opponent` | objeto | Rival. |
| `[].next_fixtures[].is_home` | booleano | Si juega en casa. |
| `[].next_fixtures[].rival_position` | entero o null | Posición del rival en la tabla real. |
| `[].next_fixtures[].difficulty` | número o null | −1 (líder, más difícil) … +1 (colista, más fácil). |
| `[].next_start` | objeto o null | Titularidad en su próximo partido (apartado 3.2); `null` = sin datos. |
| `[].next_start.fixture_id` | entero | Partido al que se refiere. |
| `[].next_start.week_number` | entero | Jornada de ese partido. |
| `[].next_start.date` | fecha y hora | Hora de inicio. |
| `[].next_start.opponent` | objeto | Rival. |
| `[].next_start.is_home` | booleano | Si juega en casa. |
| `[].next_start.probability` | entero o null | 0–100 según FútbolFantasy. |
| `[].next_start.predicted_starter` | booleano | Si está en el once probable de FútbolFantasy. |
| `[].next_start.confirmed_starter` | booleano o null | Alineación confirmada: `true` titular, `false` no titular; `null` = sin confirmar. |
| `[].next_start.source` | texto | `worldcup26` (alineación oficial) o `futbolfantasy`. |
| `[].next_start.is_stale` | booleano | Dato de más de 48 horas. |
| `[].next_start.fetched_at` | fecha y hora o null | Cuándo se obtuvo el dato de FútbolFantasy. |
| `[].next_start.source_url` | texto | Página de FútbolFantasy del equipo (para citarla). |
| `[].value_trend_30d` | objeto o null | Evolución del valor en unos 30 días. |
| `[].value_trend_30d.multiple` | número | Valor actual ÷ valor de entonces. |
| `[].value_trend_30d.value` | entero (€) | Valor de entonces. |
| `[].value_trend_30d.date` | fecha | Fecha de ese valor. |
| `[].points_per_million` | objeto o null | Rentabilidad: puntos por millón de valor. |
| `[].points_per_million.value` | número | Puntos por millón. |
| `[].points_per_million.rank` | entero o null | Su puesto (1 = el más rentable); `null` sin puntos. |
| `[].points_per_million.ranked` | entero | Jugadores en esa clasificación. |
| `[].owner_gain` | objeto o null | Plusvalía de su dueño actual. |
| `[].owner_gain.amount` | entero (€) | Valor actual − lo que pagó. |
| `[].owner_gain.paid` | entero (€) | Lo que pagó. |
| `[].owner_gain.type` | texto | `signing` o `buyout`. |
| `[].owner_gain.occurred_at` | fecha y hora | Cuándo lo compró. |
| `meta.current_page` | entero | Página actual. |
| `meta.last_page` | entero | Última página. |
| `meta.per_page` | entero | 15. |
| `meta.total` | entero | Jugadores que cumplen los filtros. |
| `links.next` | texto o null | URL de la página siguiente, con los filtros; `null` en la última. |

### GET /api/players/{id}

La ficha completa de un jugador: la misma forma que en `/api/players` (sin `recent_scores`), más su anuncio en el mercado, su historial de valor, **su puntuación jornada a jornada con el desglose** y su historial de fichajes. `{id}` sale de `/api/players`, `/api/market`, `/api/activity` o de una plantilla. Un jugador aún no vinculado a la Fantasy devuelve 404.

```json
{
  "data": {
    "id": 646, "nickname": "Raphinha", "...": "misma forma que en /api/players",
    "market_listing": null,
    "market_history": [ { "date": "2026-09-27", "value": 167125715 }, { "date": "2026-09-28", "value": 169996294 } ],
    "scores": [
      {
        "fixture_id": 5, "week_number": 1, "fixture_state": "finished",
        "opponent": { "id": 35, "name": "Athletic Club", "logo": "…" }, "is_home": true,
        "points": 15, "minutes": 63, "marca_points": 4,
        "starter": true, "subbed_in": false, "subbed_out": true, "sub_minute": 63,
        "stats": { "mins_played": [63, 2], "goals": [1, 4], "marca_points": [-1, 4], "…": "…" },
        "lineup_manager": null
      }
    ],
    "ownership_activity": [ "misma forma que /api/activity" ]
  }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `id` | entero | Id del jugador. |
| `nickname` | texto | Apodo. |
| `status` | texto | Como en `/api/players`, y además `out_of_league` (fuera de la liga: ya no puntúa). |
| `position` | texto | Posición en el campo (la de esta respuesta manda). |
| `team.name` | texto | Su equipo real actual. |
| `market_value` | entero (€) | Valor de mercado actual. |
| `market_trend` | texto o null | Tendencia de mercado. |
| `points` | entero | Puntos de la temporada. |
| `average_points` | número | Media por partido. |
| `owner_manager` | objeto o null | Dueño; `null` = libre. |
| `next_fixtures` | lista | Como en `/api/players`. |
| `next_start` | objeto o null | Como en `/api/players`. |
| `value_trend_30d` | objeto o null | Como en `/api/players`. |
| `points_per_million` | objeto o null | Como en `/api/players`. |
| `owner_gain` | objeto o null | Como en `/api/players`. |
| `market_listing` | objeto o null | Su anuncio en el mercado de la liga ahora mismo; `null` si no está. |
| `market_listing.sale_price` | entero (€) | Precio con el que salió al mercado; fijo mientras dure el anuncio. |
| `market_listing.market_value` | entero (€) | Su valor de mercado actual. |
| `market_listing.bids` | entero | Número de pujas (nunca importes ni quién). |
| `market_listing.expires_at` | fecha y hora | Cuándo acaba el anuncio. |
| `market_listing.seller` | texto | `league`: lo vende la liga. |
| `market_history` | lista | Su valor de mercado día a día, del más antiguo al más reciente. |
| `market_history[].date` | fecha | Día. |
| `market_history[].value` | entero (€) | Valor de mercado ese día. |
| `scores` | lista | Un registro por partido de la temporada con datos de alineación, de la jornada 1 en adelante. |
| `scores[].fixture_id` | entero | Partido, para `/api/fixtures/{id}` (eventos). |
| `scores[].week_number` | entero | Jornada. |
| `scores[].fixture_state` | texto | Estado del partido (si está en juego, los puntos son provisionales). |
| `scores[].opponent` | objeto | Rival. |
| `scores[].is_home` | booleano | Si jugó en casa, con el equipo que tenía ese día. |
| `scores[].points` | entero o null | Puntos fantasy de ese partido. |
| `scores[].minutes` | entero o null | Minutos jugados. |
| `scores[].marca_points` | entero o null | Puntos de la nota DAZN (0–4). |
| `scores[].starter` | booleano | Si fue titular. |
| `scores[].subbed_in` | booleano | Si entró desde el banquillo. |
| `scores[].subbed_out` | booleano | Si fue sustituido. |
| `scores[].sub_minute` | entero o null | Minuto del cambio. |
| `scores[].stats` | objeto o null | Desglose `{clave: [valor, puntos]}` (apartado 2.3). |
| `scores[].lineup_manager` | objeto o null | El manager que lo alineó esa jornada `{id, name}`; `null` si nadie. |
| `ownership_activity` | lista | Sus fichajes, ventas y cláusulas de la temporada, del más antiguo al más reciente (forma de `/api/activity`). |
| `ownership_activity[].type` | texto | `signing`, `sale` o `buyout`. |

### GET /api/market

El mercado de la liga ahora mismo: los jugadores que ha sacado la liga, del que antes caduca al que más tarda. Sin parámetros ni paginación. No incluye jugadores que ponen a la venta los managers ni anuncios caducados. Si está vacío, mira `/api/season` → `next_market_renewal_at`.

```json
{
  "data": [
    {
      "player": { "id": 125, "nickname": "Areso", "...": "misma forma que en /api/players" },
      "sale_price": 3707104, "market_value": 3707104, "bids": 2,
      "expires_at": "2026-09-28T20:00:00+02:00", "seller": "league"
    }
  ]
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].player` | objeto | El jugador, con la forma completa de `/api/players` (incluye `next_start`). |
| `[].player.id` | entero | Id del jugador. |
| `[].sale_price` | entero (€) | Precio con el que salió al mercado; fijo durante el anuncio. |
| `[].market_value` | entero (€) | Su valor de mercado actual. |
| `[].bids` | entero | Número de pujas; nunca importes ni quién. |
| `[].expires_at` | fecha y hora | Cuándo acaba el anuncio (con la renovación de las 20:00). |
| `[].seller` | texto | `league`: lo vende la liga. |

### GET /api/activity

La actividad de la liga (fichajes, ventas, cláusulas, blindajes, cobros de jornada y altas de managers), de la más reciente a la más antigua. 30 por página.

| Parámetro | Valores | Ejemplo |
|---|---|---|
| `manager` | Ids de manager, separados por comas; incluye movimientos donde es origen **o** destino | `?manager=4` |
| `player` | Ids de jugador, separados por comas | `?player=88` |
| `type` | Uno o varios de `signing`, `sale`, `buyout`, `shield`, `weekly_prize`, `joined_league` | `?type=signing,buyout` |
| `page` | Página, desde 1 | `?page=2` |

```json
{
  "data": [
    {
      "id": 622, "type": "signing", "type_label": "Fichaje", "occurred_at": "2026-09-27T20:01:22+02:00",
      "source_manager": { "id": 4, "name": "CID F.C" }, "target_manager": null,
      "player": { "id": 125, "nickname": "Areso" }, "amount": 3707104, "week_number": null, "value_difference": 1
    }
  ]
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].id` | entero | Id del movimiento. |
| `[].type` | texto | `signing` (fichaje), `sale` (venta), `buyout` (cláusula pagada), `shield` (blindaje), `weekly_prize` (cobro de la jornada: lo reciben todos los managers, no indica quién ganó) o `joined_league` (alta de manager). |
| `[].type_label` | texto | `type` en español, para mostrar. |
| `[].occurred_at` | fecha y hora | Cuándo ocurrió. |
| `[].source_manager` | objeto | Quien lo origina (quien ficha, vende, paga la cláusula o cobra) `{id, name}`. |
| `[].source_manager.id` | entero | Id del manager. |
| `[].source_manager.name` | texto | Nombre del manager. |
| `[].target_manager` | objeto o null | Solo en `buyout`: el manager que pierde al jugador. |
| `[].player` | objeto o null | `{id, nickname}`; `null` en `weekly_prize` y `joined_league`. |
| `[].player.id` | entero | Id del jugador. |
| `[].player.nickname` | texto | Apodo. |
| `[].amount` | entero (€) o null | Importe; `null` si el tipo no tiene importe. |
| `[].week_number` | entero o null | Solo en `weekly_prize`: la jornada cobrada. |
| `[].value_difference` | entero (€) o null | `amount` menos el valor de mercado del jugador ese día. Positivo = pagó por encima de su valor. |
| `meta.current_page` | entero | Página actual. |
| `meta.total` | entero | Movimientos que cumplen los filtros. |
| `links.next` | texto o null | Página siguiente, con los filtros. |

### GET /api/fixtures

El calendario completo de la temporada, agrupado por jornada. Sin parámetros. Es una respuesta grande: para saber "qué pasa ahora", usa `/api/season`.

```json
{
  "data": [
    {
      "week_number": 1,
      "fixtures": [
        {
          "id": 10, "url": "https://comandolechuga.com/api/fixtures/10", "week_number": 1,
          "date": "2026-08-15T19:30:00+02:00", "state": "finished", "state_label": "Finalizado", "display_clock": "FT",
          "local_team": { "id": 21, "name": "Deportivo Alavés", "logo": "…" },
          "guest_team": { "id": 22, "name": "Getafe CF", "logo": "…" },
          "local_score": 3, "guest_score": 0
        }
      ]
    }
  ]
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].week_number` | entero | Jornada. |
| `[].fixtures` | lista | Sus partidos, por fecha. |
| `[].fixtures[].id` | entero | Id del partido. |
| `[].fixtures[].url` | texto | URL de su ficha. |
| `[].fixtures[].week_number` | entero | Jornada. |
| `[].fixtures[].date` | fecha y hora | Hora de inicio. |
| `[].fixtures[].state` | texto | `scheduled` (programado), `first_half`, `half_time`, `second_half` (en juego), `finished` o `postponed` (aplazado). |
| `[].fixtures[].state_label` | texto | El estado en español. |
| `[].fixtures[].display_clock` | texto o null | El reloj tal como se muestra (p. ej. `"90'+6'"`, `"FT"`). |
| `[].fixtures[].local_team` | objeto | Equipo local. |
| `[].fixtures[].local_team.id` | entero | Id del equipo. |
| `[].fixtures[].local_team.name` | texto | Nombre. |
| `[].fixtures[].guest_team` | objeto | Equipo visitante. |
| `[].fixtures[].local_score` | entero o null | Goles del local; `null` antes de empezar. |
| `[].fixtures[].guest_score` | entero o null | Goles del visitante. |

### GET /api/fixtures/{id}

La ficha de un partido: el marcador, las alineaciones con puntos y desglose, los eventos y las estadísticas por equipo. `{id}` sale de `/api/fixtures`, `/api/season`, `scores[].fixture_id` o `current_lineup.players[].match.fixture_id`.

```json
{
  "data": {
    "id": 63, "week_number": 7, "state": "finished", "state_label": "Finalizado", "display_clock": "90'+4'",
    "local_score": 2, "guest_score": 1, "local_formation": "4-3-3", "guest_formation": "4-4-2",
    "venue": "Estadi Olímpic Lluís Companys", "venue_city": "Barcelona", "attendance": 48000,
    "referee": "…", "local_possession": 58.5, "guest_possession": 41.5,
    "lineups": [ { "id": 3878, "player": { "id": 197, "nickname": "Djene", "image": "…" }, "team_id": 22, "starter": true, "pitch_position": "Center Defender", "points": 6, "stats": { "…": "…" }, "…": "…" } ],
    "events": [ { "id": 2106, "minute": 83, "type": "goal", "team_id": 33, "player": { "id": 672, "nickname": "Pablo García" }, "is_own_goal": false, "is_penalty": false, "label": null } ],
    "team_stats": [ { "stat": "shotsOnTarget", "label": "Tiros a puerta", "local": 3, "guest": 3 } ]
  }
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `id` | entero | Id del partido. |
| `url` | texto | URL de esta ficha. |
| `date` | fecha y hora | Hora de inicio. |
| `week_number` | entero | Jornada. |
| `state` | texto | Como en `/api/fixtures`. |
| `state_label` | texto | Estado en español. |
| `display_clock` | texto o null | Reloj del partido. |
| `local_team` | objeto | Equipo local. |
| `guest_team` | objeto | Equipo visitante. |
| `local_score` | entero o null | Goles del local. |
| `guest_score` | entero o null | Goles del visitante. |
| `local_formation` | texto o null | Formación **real** del local (no es fantasy). |
| `guest_formation` | texto o null | Formación real del visitante. |
| `venue` | texto | Estadio. |
| `venue_city` | texto | Ciudad. |
| `attendance` | entero o null | Espectadores. |
| `referee` | texto | Árbitro. |
| `local_possession` | número o null | Posesión del local (%). |
| `guest_possession` | número o null | Posesión del visitante (%). |
| `lineups` | lista | Todos los convocados de ambos equipos (titulares y suplentes). |
| `lineups[].id` | entero | Id de la fila. |
| `lineups[].player` | objeto o null | `{id, nickname, image}`; `null` si no está vinculado (usa `unresolved_name`). |
| `lineups[].player.id` | entero | Id del jugador. |
| `lineups[].unresolved_name` | texto o null | Nombre en bruto de un jugador no vinculado. |
| `lineups[].team_id` | entero | Equipo con el que juega. |
| `lineups[].starter` | booleano | Titular. |
| `lineups[].pitch_position` | texto | Puesto real en el campo (vocabulario de worldcup26, p. ej. `"Right Back"`; `"Substitute"` en el banquillo). No es la posición fantasy. |
| `lineups[].jersey` | texto | Dorsal. |
| `lineups[].subbed_in` | booleano | Entró desde el banquillo. |
| `lineups[].subbed_out` | booleano | Fue sustituido. |
| `lineups[].sub_minute` | entero o null | Minuto del cambio. |
| `lineups[].counterpart_player` | objeto o null | El otro jugador del cambio `{id, nickname}`. |
| `lineups[].points` | entero o null | Puntos fantasy en este partido. |
| `lineups[].stats` | objeto | Desglose `{clave: [valor, puntos]}` (apartado 2.3). |
| `events` | lista | Goles, tarjetas y decisiones del VAR, por minuto. |
| `events[].id` | entero | Id del evento. |
| `events[].minute` | entero | Minuto. |
| `events[].type` | texto | `goal`, `yellow_card`, `red_card` o `var`. |
| `events[].team_id` | entero | Equipo. |
| `events[].player` | objeto o null | `{id, nickname}`; `null` si no está vinculado. |
| `events[].player.id` | entero | Id del jugador. |
| `events[].unresolved_name` | texto o null | Nombre en bruto. |
| `events[].is_own_goal` | booleano | Gol en propia puerta (solo `goal`). |
| `events[].is_penalty` | booleano | De penalti (solo `goal`). |
| `events[].label` | texto o null | Solo en `var`: "Decisión del VAR" o "Tarjeta ascendida". |
| `team_stats` | lista | Estadísticas comparadas: 8 fijas (tiros a puerta, tiros totales, faltas, fueras de juego, paradas, asistencias, amarillas, rojas), más córners y pases clave cuando hay datos. |
| `team_stats[].stat` | texto | Clave en inglés (estable). |
| `team_stats[].label` | texto | Etiqueta en español. |
| `team_stats[].local` | entero | Valor del local. |
| `team_stats[].guest` | entero | Valor del visitante. |

### GET /api/teams

La clasificación real de LaLiga (partidos terminados y en juego) y, para cada equipo, su próximo partido con el once probable (FútbolFantasy) o confirmado (worldcup26): la formación real y el % de cada jugador. Sin parámetros.

```json
{
  "data": [
    {
      "rank": 1, "team": { "id": 39, "name": "FC Barcelona", "logo": "…" },
      "played": 7, "won": 6, "drawn": 1, "lost": 0, "goals_for": 19, "goals_against": 5, "goal_difference": 14, "points": 19,
      "recent_form": [ { "fixture_id": 63, "opponent": { "id": 30, "name": "Elche CF", "logo": "…" }, "score": "3-1", "result": "win", "date": "2026-09-27T21:00:00+02:00" } ],
      "live": null,
      "next_fixture": {
        "fixture_id": 75, "url": "https://comandolechuga.com/api/fixtures/75", "week_number": 8, "date": "2026-10-04T21:00:00+02:00",
        "opponent": { "id": 24, "name": "Rayo Vallecano", "logo": "…" }, "is_home": true,
        "lineup": {
          "source": "futbolfantasy", "confirmed": false, "formation": "4-3-3", "is_stale": false,
          "fetched_at": "2026-09-28T17:50:00+02:00", "source_url": "https://www.futbolfantasy.com/laliga/equipos/barcelona",
          "players": [ { "player": { "id": 646, "url": "…", "nickname": "Raphinha", "position": "striker" }, "probability": 95, "predicted_starter": true, "confirmed_starter": null, "pitch_position": "Left Winger" } ]
        }
      }
    }
  ]
}
```

| Campo | Tipo | Significado |
|---|---|---|
| `[].rank` | entero | Posición en la tabla real (puntos, luego diferencia de goles, luego goles a favor). |
| `[].team` | objeto | El equipo `{id, name, logo}`. Su `id` sirve para el filtro `team=` de `/api/players`. |
| `[].team.id` | entero | Id del equipo. |
| `[].team.name` | texto | Nombre. |
| `[].played` | entero | Partidos jugados. |
| `[].won` | entero | Ganados. |
| `[].drawn` | entero | Empatados. |
| `[].lost` | entero | Perdidos. |
| `[].goals_for` | entero | Goles a favor. |
| `[].goals_against` | entero | Goles en contra. |
| `[].goal_difference` | entero | Diferencia de goles. |
| `[].points` | entero | Puntos de liga (3 por victoria, 1 por empate). |
| `[].recent_form` | lista | Hasta 4 últimos resultados, del más reciente al más antiguo. |
| `[].recent_form[].fixture_id` | entero | Partido. |
| `[].recent_form[].opponent` | objeto | Rival. |
| `[].recent_form[].score` | texto | Marcador desde su lado, p. ej. `"2-1"`. |
| `[].recent_form[].result` | texto | `win`, `draw` o `loss`. |
| `[].recent_form[].date` | fecha y hora | Fecha. |
| `[].live` | objeto o null | Su partido en juego ahora (misma forma que `recent_form[]`, con el marcador actual); `null` si no juega. |
| `[].next_fixture` | objeto o null | Su próximo partido programado; `null` si no le quedan. |
| `[].next_fixture.fixture_id` | entero | Id del partido. |
| `[].next_fixture.url` | texto | URL de su ficha. |
| `[].next_fixture.week_number` | entero | Jornada. |
| `[].next_fixture.date` | fecha y hora | Hora de inicio. |
| `[].next_fixture.opponent` | objeto | Rival. |
| `[].next_fixture.is_home` | booleano | Si juega en casa. |
| `[].next_fixture.lineup` | objeto o null | Once probable o confirmado; `null` si aún no hay datos. |
| `[].next_fixture.lineup.source` | texto | `worldcup26` (alineación oficial) o `futbolfantasy` (cítalo). |
| `[].next_fixture.lineup.confirmed` | booleano | Si la alineación ya está confirmada. |
| `[].next_fixture.lineup.formation` | texto o null | Formación **real** del equipo (no es fantasy). |
| `[].next_fixture.lineup.is_stale` | booleano | Dato de más de 48 horas. |
| `[].next_fixture.lineup.fetched_at` | fecha y hora o null | Cuándo se obtuvo. |
| `[].next_fixture.lineup.source_url` | texto | Página de FútbolFantasy del equipo. |
| `[].next_fixture.lineup.players` | lista | Jugadores con dato. |
| `[].next_fixture.lineup.players[].player.id` | entero | Id del jugador. |
| `[].next_fixture.lineup.players[].player.url` | texto | URL de su ficha. |
| `[].next_fixture.lineup.players[].player.nickname` | texto | Apodo. |
| `[].next_fixture.lineup.players[].player.position` | texto o null | Posición fantasy en el campo. |
| `[].next_fixture.lineup.players[].probability` | entero o null | % de titularidad de FútbolFantasy. |
| `[].next_fixture.lineup.players[].predicted_starter` | booleano | En el once probable. |
| `[].next_fixture.lineup.players[].confirmed_starter` | booleano o null | Titular confirmado; `null` sin confirmar. |
| `[].next_fixture.lineup.players[].pitch_position` | texto o null | Su puesto real en el once (p. ej. `"Right Back"`). |

---

## 6. Glosario

| Español | English | En la API |
|---|---|---|
| jornada | matchweek | `week_number`, `/api/season` |
| clasificación de la liga | league standings | `/api/standings` |
| posición en la liga | league rank | `rank`, `last_rank` |
| posición en el campo | playing position (GK/DEF/MID/FWD) | `position` (`goalkeeper`, `defender`, `midfield`, `striker`) |
| puesto real en el campo | on-pitch role | `pitch_position` |
| valor de mercado | market value | `market_value` |
| valor de la plantilla | squad value | `squad_value` |
| saldo | cash balance | no está en la API: pregúntalo |
| puntos | fantasy points | `points` |
| media | average points | `average_points` |
| once / alineación | starting XI / lineup | `current_lineup`, `lineup_history` |
| formación | formation | `formation`, `tactical_formation` |
| titular / suplente | starter / substitute | `starter`, `confirmed_starter` |
| probabilidad de titularidad | start probability | `next_start.probability` |
| mercado | transfer market | `/api/market` |
| puja | bid | `bids` |
| fichaje | signing | `signing` |
| venta | sale | `sale` |
| cláusula / clausulazo | buyout clause / buyout | `buyout_clause`, `buyout` |
| blindaje | shield | `shield`, `shielded` |
| cobro de la jornada | weekly payout | `weekly_prize` |
| manager | fantasy manager (league member) | `manager`, `source_manager`, `owner_manager` |
| equipo real | LaLiga club | `team` |
| libre | free agent (unowned) | `owner_manager: null`, `free=1` |
| nota DAZN | DAZN rating | `marca_points` |
| aplazado | postponed | `postponed` |
| en juego | live | `live`, `first_half`, `half_time`, `second_half` |
| tendencia de mercado | market trend | `market_trend` |
| dificultad del rival | opponent difficulty | `difficulty`, `rival_position` |
| plusvalía | owner's paper gain | `owner_gain` |

---

## 7. Cambios respecto a la versión anterior de la API

Estos cambios **rompen** a los clientes que usaban la versión anterior:

- `/api/players`: el filtro `season_manager` ahora es `manager`.
- Actividad (en `/api/activity`, `recent_activity` y `ownership_activity`): `source_season_manager` y `target_season_manager` ahora son `source_manager` y `target_manager`.
- `/api/standings` y `/api/managers/{id}`: `position` → `rank`, `last_position` → `last_rank`, `value` → `squad_value`.
- `/api/market` y `market_listing`: `value` → `market_value`; nuevo `seller`.
- `/api/fixtures/{id}`: `lineups[].position` → `lineups[].pitch_position`.
- `/api/managers/{id}`:
  - `roster[].buyout_clause` ahora es un objeto (`amount`, `locked_until`, `is_locked`, `shielded`, `shielded_until`);
  - `roster[].player` tiene la forma completa de `/api/players`;
  - `lineup_history` solo trae jornadas terminadas;
  - la jornada en curso o próxima está en `current_lineup`.
- `average_points` es un número, no un texto.
- En `/api/players` y `/api/activity`, un parámetro desconocido o un valor no válido devuelve 422 en lugar de ignorarse (también `position=coach` y `status=out_of_league`).

Novedades:
- `/api/season` y `/api/teams`.
- `meta.generated_at` y `meta.timezone` en todas las respuestas.
- En cada jugador: `next_start`, `value_trend_30d`, `points_per_million` y `owner_gain`; en sus próximos partidos, la dificultad del rival.
- En `scores[]`: los minutos, la nota DAZN y el estado real en el partido.
- En `/api/players`: los filtros `free`, `min_value`, `max_value` y `min_start_probability`, y los órdenes `trend` y `points_per_million`.
- En `/api/managers/{id}`: sus puestos por jornada, su media y el cambio diario del valor de la plantilla.
````

- [ ] **Step 4: Run the docs tests**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/ApiDocsControllerTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add resources/docs/api-docs.md tests/Feature/Http/Controllers/ApiDocsControllerTest.php
git commit -m "docs: rewrite the API docs as a guide for the managers' AI advisor" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 9: Doc-drift test: every documented endpoint and field exists in real responses

**Files:**
- Create: `tests/Feature/Http/Controllers/Api/ApiDocsDriftTest.php`
- Modify (only if the test finds drift): `resources/docs/api-docs.md` and/or `tests/Feature/Http/Controllers/Api/ApiWorld.php`

**Interfaces:**
- Consumes: `ApiWorld::seed()` (Task 1); the doc format pinned in Task 8 (`### GET /api/…` headings, `| Campo |` tables, path syntax).
- Produces: a test that fails when the reference and the API disagree, in either direction: an undocumented route, a documented route that doesn't exist, or a documented field that no sample response has. It also fails when the doc names the private bid model.

- [ ] **Step 1: Write the drift test**

Create `tests/Feature/Http/Controllers/Api/ApiDocsDriftTest.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Http\Controllers\Api\ApiWorld;

/**
 * The reference part of resources/docs/api-docs.md, parsed: each
 * "### GET /api/…" heading and, under it, the first-column paths of every
 * table whose header starts with "| Campo |".
 *
 * @return array<string, list<string>>  normalised uri (e.g. "api/players/{}") => field paths
 */
function documentedApiReference(): array
{
    $lines = file(resource_path('docs/api-docs.md'), FILE_IGNORE_NEW_LINES);
    $reference = [];
    $endpoint = null;
    $inFieldTable = false;

    foreach ($lines === false ? [] : $lines as $line) {
        if (preg_match('/^### GET \/(api\/\S+)$/', $line, $match) === 1) {
            $endpoint = normalizedApiUri($match[1]);
            $reference[$endpoint] = [];
            $inFieldTable = false;

            continue;
        }

        if (str_starts_with($line, '## ') || str_starts_with($line, '### ')) {
            $endpoint = null;

            continue;
        }

        if ($endpoint === null) {
            continue;
        }

        if (preg_match('/^\|\s*Campo\s*\|/', $line) === 1) {
            $inFieldTable = true;

            continue;
        }

        if (!str_starts_with($line, '|')) {
            $inFieldTable = false;

            continue;
        }

        if ($inFieldTable && preg_match('/^\|\s*`([^`]+)`/', $line, $match) === 1) {
            $reference[$endpoint][] = $match[1];
        }
    }

    return $reference;
}

function normalizedApiUri(string $uri): string
{
    return (string) preg_replace('/\{[^}]+\}/', '{}', $uri);
}

/**
 * "roster[].player.id" → ["roster", "[]", "player", "id"]; "[].rank" → ["[]", "rank"].
 *
 * @return list<string>
 */
function apiPathSegments(string $path): array
{
    $segments = [];

    foreach (explode('.', $path) as $part) {
        if ($part === '[]') {
            $segments[] = '[]';
        } elseif (str_ends_with($part, '[]')) {
            $segments[] = substr($part, 0, -2);
            $segments[] = '[]';
        } else {
            $segments[] = $part;
        }
    }

    return $segments;
}

/**
 * Whether the path exists in a decoded JSON value. "[]" matches when ANY
 * element of a non-empty list has the rest of the path. A key holding null
 * exists; descending into a null does not.
 *
 * @param  list<string>  $segments
 */
function apiPathExists(mixed $value, array $segments): bool
{
    if ($segments === []) {
        return true;
    }

    $segment = array_shift($segments);

    if ($segment === '[]') {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (apiPathExists($item, $segments)) {
                return true;
            }
        }

        return false;
    }

    return is_array($value) && array_key_exists($segment, $value) && apiPathExists($value[$segment], $segments);
}

beforeEach(function (): void {
    $this->world = ApiWorld::seed();
});

test('documents every public api endpoint, and only those', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/'))
        ->map(fn (RoutingRoute $route): string => normalizedApiUri($route->uri()))
        ->sort()
        ->values()
        ->all();

    $documented = collect(array_keys(documentedApiReference()))->sort()->values()->all();

    expect($documented)->toBe($routes);
});

test('documents at least one field for every endpoint', function (): void {
    foreach (documentedApiReference() as $endpoint => $fields) {
        expect($fields)->not->toBeEmpty("{$endpoint} has no field table");
    }
});

test('every documented field exists in a real response', function (): void {
    $world = $this->world;
    $samples = [
        'api/season' => ['/api/season'],
        'api/standings' => ['/api/standings'],
        'api/managers/{}' => ["/api/managers/{$world->managerId}", "/api/managers/{$world->rivalManagerId}"],
        'api/players' => ['/api/players'],
        'api/players/{}' => ["/api/players/{$world->ownedPlayerId}", "/api/players/{$world->listedPlayerId}"],
        'api/market' => ['/api/market'],
        'api/activity' => ['/api/activity'],
        'api/fixtures' => ['/api/fixtures'],
        'api/fixtures/{}' => ["/api/fixtures/{$world->finishedFixtureId}"],
        'api/teams' => ['/api/teams'],
    ];

    foreach (documentedApiReference() as $endpoint => $fields) {
        expect($samples)->toHaveKey($endpoint);

        $bodies = array_map(function (string $url): array {
            $response = $this->getJson($url);
            $response->assertOk();

            return (array) $response->json();
        }, $samples[$endpoint]);

        foreach ($fields as $path) {
            $segments = apiPathSegments($path);
            $fromRoot = in_array($segments[0], ['meta', 'links'], true);

            $found = collect($bodies)->contains(
                fn (array $body): bool => apiPathExists($fromRoot ? $body : ($body['data'] ?? null), $segments),
            );

            expect($found)->toBeTrue("{$endpoint}: documented field `{$path}` is missing from every sample response");
        }
    }
});

test('never names the private bid model', function (): void {
    $doc = mb_strtolower((string) file_get_contents(resource_path('docs/api-docs.md')));

    foreach (['puja máxima', 'puja maxima', 'max bid', 'max_bid', 'maxbid', 'god mode', 'god_mode', 'godmode'] as $term) {
        expect(str_contains($doc, $term))->toBeFalse("the docs mention \"{$term}\"");
    }
});
```

- [ ] **Step 2: Run it**

Run: `herd php artisan test --compact tests/Feature/Http/Controllers/Api/ApiDocsDriftTest.php`
Expected: PASS (4 tests).

If a field is reported missing, find out which side is wrong:
- If the name in the doc differs from the resource, fix the doc to match the code (Tasks 2–7 are the source of truth for names).
- If the field exists but is `null` or empty in every sample, add the missing data to `ApiWorld`, for example a second listing or a lineup player. The world must satisfy every documented path.

Never delete a documented field just to make the test pass. Remove one only if the spec doesn't ask for it.

- [ ] **Step 3: Run the whole suite, format, analyse**

Run: `herd php artisan test --compact`
Expected: PASS (whole suite).

Run: `herd php vendor/bin/pint --dirty --format agent` then `herd composer phpstan`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Http/Controllers/Api/ApiDocsDriftTest.php resources/docs/api-docs.md tests/Feature/Http/Controllers/Api/ApiWorld.php
git commit -m "test: fail when the API docs and the real responses drift apart" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

---

### Task 10: AI dry run: an agent that reads only `/api-docs` answers typical manager questions

No automated test can check this. It is a manual verification by a fresh agent, and it gates the branch.

**Files:**
- Modify (only if the dry run fails a check): `resources/docs/api-docs.md`
- No new files. The findings go into the task report, not a file.

**Interfaces:**
- Consumes: the live local site served by Herd. Resolve the base URL with Boost's `get-absolute-url`; normally it is `https://comando-lechuga.test`. It needs `/api-docs` and `/api/*` with local data. **Do not start `npm run dev`**; the API and the docs don't need it.
- Produces: a pass/fail checklist with evidence (the agent's transcript and the URLs it requested).

- [ ] **Step 1: Confirm the local API answers**

Run: `curl -sk https://comando-lechuga.test/api/season`
Expected: a JSON body with `data.current_week` and `meta.generated_at`. If it fails, stop and ask the user. Do not start servers.

- [ ] **Step 2: Dispatch a fresh agent with only the docs**

Dispatch a general-purpose subagent named `Asesor · dry run`, with no project context, and this prompt (replace `{BASE}` with the resolved base URL):

```text
Eres un asesor IA para un manager de una liga de LaLiga Fantasy. Tu única fuente es la guía {BASE}/api-docs y la API que describe (usa {BASE}/api en lugar de https://comandolechuga.com/api). Léela entera primero. Después atiende al usuario, que te escribirá estos mensajes uno detrás de otro. Responde a cada uno como lo harías en una conversación real y, para cada respuesta, lista las URLs que has pedido.

1. "Hola, nunca he usado esto. ¿Me ayudas con mi equipo?"
2. (cuando preguntes quién es) "Soy <nombre del manager que está 2º en la clasificación>. Quiero ayuda con fichajes, cláusulas y la alineación."
3. "¿A quién ficho hoy del mercado y cuánto ofrezco?"
4. "¿Puedo clausular ahora mismo al mejor jugador de <nombre del manager que va 1º>? ¿Me compensa?"
5. "Proponme el once para la próxima jornada."
6. "¿Por qué <un jugador de mi plantilla> sacó los puntos que sacó en la última jornada que jugó?"
7. "Dame el resumen rápido del día."
8. "¿Y a quién ficho hoy?" (la misma pregunta que la 3, otra vez)
```

- [ ] **Step 3: Check the transcript against this checklist**

Mark each item pass/fail and cite the transcript line or URL:

1. **Onboarding:** before advising, it asks "¿Qué manager eres?" with the list from `/api/standings` and the "Prefiero no decirlo" option, then offers the 9 intentions.
2. **No cache:** every answer (including message 8, the repeat of 3) requests the API again. It never says "como vimos antes" without new requests.
3. **Freshness:** it quotes the data time (`meta.generated_at`) at least once for market or lineup data.
4. **Balance:** it asks for the manager's balance before recommending a bid amount or a buyout, and says the API doesn't have it.
5. **Bid reasoning:** it justifies the offer with public data (value, trend, points, start probability, rivals, number of bids). It never names or describes any private bid model, "puja máxima" or a 14-day projection.
6. **Buyouts:** it checks `/api/season` → `buyouts_open`, `is_locked`/`locked_until` and `shielded` before answering message 4, and mentions that a buyout can't leave the balance negative.
7. **Market rules:** it states the 20:00 renewal (Madrid), the 24 h listing and that bids are blind (count only).
8. **Lineup:** the proposed XI fits exactly one of 3-4-3, 3-5-2, 4-3-3, 4-4-2, 4-5-1, 5-3-2 or 5-4-1, with 1 goalkeeper. It gives the lock time (`upcoming_week.lineup_locks_at`), warns about injured/suspended/doubtful players, and does not propose a captain, bench, coach or loan.
9. **Scoring:** message 6 is explained line by line from `scores[].stats` (`[valor, puntos]`), including `marca_points` as the DAZN rating, without inventing a per-stat DAZN formula.
10. **FútbolFantasy credit:** every use of a start probability is credited to FútbolFantasy.
11. **No invented rules:** it asserts no per-club limit, no minimum squad and no other rule absent from the guide ("consúltalo en la app" instead).
12. **Uses the API correctly:** no 422s left unhandled, filters used instead of paging all players, and IDs taken from responses.

- [ ] **Step 4: Fix the docs and re-run until every item passes**

For each failed item, change the smallest thing in `resources/docs/api-docs.md` that would have prevented it (usually a sharper rule in section 1.1 or a row in section 4). Re-run Task 9's drift test (`herd php artisan test --compact tests/Feature/Http/Controllers/Api/ApiDocsDriftTest.php`), then dispatch a **new** agent (fresh context) with the same prompt. Stop when all 12 items pass. If a failure needs an API change rather than a doc change, stop and report it to the user instead of improvising.

- [ ] **Step 5: Commit any doc fixes and report**

If the doc changed:

```bash
git add resources/docs/api-docs.md
git commit -m "docs: tighten the AI advisor guide after the dry run" -m "Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>"
```

Report to the user the checklist results (12 × pass/fail with evidence), the number of dry-run rounds and any doc changes. Do not merge the branch; wait for the user's OK.
