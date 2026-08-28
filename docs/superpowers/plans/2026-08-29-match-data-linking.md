# Match Data Linking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `match_data_id` identifier to `teams`, `fixtures`, and `players`, linking each to its equivalent resource on worldcup26.ir, so a later phase can sync real match data (lineups, goals, cards) against our own rows.

**Architecture:** A new Saloon connector (`Worldcup26Connector`) mirrors the existing `LaLigaFantasyConnector` pattern. Teams are linked via a one-time hardcoded mapping (20 rows, no ambiguity — validated against real data). Fixtures are linked by a console command matching worldcup26's fixture list against ours by team pair + date. Players are linked by a console command that, per already-linked fixture, fetches its roster and runs a dedicated, independently-testable matcher (`MatchDataPlayerMatcher`) using a staged chain of name-matching rules, from strictest to loosest — never guessing when a rule produces more than one candidate.

**Tech Stack:** Laravel 12, Saloon (HTTP connector), Eloquent, Pest.

**Spec:** `docs/superpowers/specs/2026-08-29-match-data-linking-design.md`

## Global Constraints

- Field name is `match_data_id` everywhere (not `worldcup26_id`) — vendor-neutral, survives a provider swap.
- `match_data_id`: unsigned integer, nullable, unique, on `teams`, `fixtures`, `players`.
- Team mapping (worldcup26 id → our `teams.id`) is exactly this table, hardcoded, no auto-matching:

  | worldcup26 id | our `teams.id` |
  |---|---|
  | 83 | 3 |
  | 86 | 13 |
  | 243 | 15 |
  | 244 | 4 |
  | 96 | 18 |
  | 1068 | 1 |
  | 97 | 11 |
  | 88 | 7 |
  | 2922 | 8 |
  | 102 | 17 |
  | 90 | 19 |
  | 87 | 20 |
  | 101 | 12 |
  | 85 | 5 |
  | 94 | 16 |
  | 99 | 10 |
  | 1538 | 9 |
  | 3751 | 6 |
  | 89 | 14 |
  | 93 | 2 |

- Never guess: whenever a matching rule (fixtures or players) produces more than one candidate, leave it unlinked rather than picking one.
- `SyncCurrentSeasonFixtures` stops writing `state`, `local_score`, `guest_score` — it remains the only source of `fantasy_id` (needed by `SyncsPlayerScores`), `week_number`, `date`, and the team pair.
- API base URL: `https://worldcup26.ir/`, no API key, endpoints under `get/soccer/esp.1/...`.

---

## Task 1: `match_data_id` migration, models, and team mapping

**Files:**
- Create: `database/migrations/2026_08_29_120000_add_match_data_id_to_teams_fixtures_players.php`
- Create: `database/migrations/2026_08_29_120100_link_teams_to_match_data.php`
- Modify: `app/Models/Team.php`
- Modify: `app/Models/Fixture.php`
- Modify: `app/Models/Player.php`

**Interfaces:**
- Produces: `teams.match_data_id`, `fixtures.match_data_id`, `players.match_data_id` (unsigned int, nullable, unique). All 20 `teams` rows populated per the Global Constraints table above. `Team`/`Fixture`/`Player` models expose `match_data_id` as a fillable, cast `int`, nullable attribute.

- [ ] **Step 1: Write the schema migration**

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
        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedInteger('match_data_id')->nullable()->unique()->after('fantasy_id');
        });

        Schema::table('fixtures', function (Blueprint $table): void {
            $table->unsignedInteger('match_data_id')->nullable()->unique()->after('fantasy_id');
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedInteger('match_data_id')->nullable()->unique()->after('fantasy_id');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('match_data_id');
        });

        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn('match_data_id');
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('match_data_id');
        });
    }
};
```

- [ ] **Step 2: Write the team-mapping data migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * worldcup26.ir team id => our teams.id — see the design spec for how
     * this was derived (validated 1:1 against real data, no ambiguity).
     *
     * @var array<int, int>
     */
    private const array TEAM_MAP = [
        83 => 3,
        86 => 13,
        243 => 15,
        244 => 4,
        96 => 18,
        1068 => 1,
        97 => 11,
        88 => 7,
        2922 => 8,
        102 => 17,
        90 => 19,
        87 => 20,
        101 => 12,
        85 => 5,
        94 => 16,
        99 => 10,
        1538 => 9,
        3751 => 6,
        89 => 14,
        93 => 2,
    ];

    public function up(): void
    {
        foreach (self::TEAM_MAP as $matchDataId => $teamId) {
            DB::table('teams')->where('id', $teamId)->update(['match_data_id' => $matchDataId]);
        }
    }

    public function down(): void
    {
        DB::table('teams')->whereIn('match_data_id', array_keys(self::TEAM_MAP))->update(['match_data_id' => null]);
    }
};
```

- [ ] **Step 3: Update the three models**

In `app/Models/Team.php`: add `'match_data_id'` to the `#[Fillable([...])]` array, add `'match_data_id' => 'int',` to `casts()`, add `@property-read int|null $match_data_id` to the class docblock.

In `app/Models/Fixture.php`: same three changes (Fillable, casts, docblock) — `Fixture`'s Fillable currently is `['fantasy_id', 'season_id', 'week_number', 'date', 'team_local_id', 'team_guest_id', 'local_score', 'guest_score', 'state']`; add `'match_data_id'`.

In `app/Models/Player.php`: same three changes — `Player`'s Fillable currently is `['fantasy_id', 'nickname', 'status', 'image', 'team_id']`; add `'match_data_id'`.

- [ ] **Step 4: Run migrations and verify the team mapping**

Run: `php artisan test` (this runs every migration against a fresh test DB as part of `RefreshDatabase` — confirms the migrations apply cleanly).

Write and run a quick throwaway check (not a committed test — this is a one-time data verification, same reasoning as the Task 1 backfill check in the Player/PlayerSeason plan): via `php artisan tinker`, run `App\Models\Team::query()->whereNotNull('match_data_id')->count()` and confirm it's `20`, and spot-check one row, e.g. `App\Models\Team::find(3)->match_data_id` should be `83` (Barcelona).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_29_120000_add_match_data_id_to_teams_fixtures_players.php database/migrations/2026_08_29_120100_link_teams_to_match_data.php app/Models/Team.php app/Models/Fixture.php app/Models/Player.php
git commit -m "feat: add match_data_id to teams/fixtures/players, link all 20 teams"
```

---

## Task 2: `Worldcup26Connector` and its two requests

**Files:**
- Create: `app/Http/Integrations/Worldcup26/Worldcup26Connector.php`
- Create: `app/Http/Integrations/Worldcup26/Requests/GetFixturesRequest.php`
- Create: `app/Http/Integrations/Worldcup26/Requests/GetEventRequest.php`
- Modify: `config/services.php`
- Test: `tests/Unit/Http/Integrations/Worldcup26/GetFixturesRequestTest.php`
- Test: `tests/Unit/Http/Integrations/Worldcup26/GetEventRequestTest.php`

**Interfaces:**
- Produces: `Worldcup26Connector::getFixtures(int $pageIndex = 1): Response`, `Worldcup26Connector::getEvent(int $matchDataId): Response`.

**Important — verify before finalizing this task's code:** the exact shape below is reconstructed from documentation lookups, not a live request this plan's author could make directly. Two specific things need confirming against a real response before you consider this task done (see Step 3):
1. The query parameter name for paging `/fixtures` — the response body's field is `pageIndex` (confirmed: `count: 380`, `pageSize: 100`, `pageCount: 4`, so it's paginated and you need all 4 pages). Try `?pageIndex=2` first; if the returned `pageIndex` in the body doesn't change accordingly, try `?page=2` instead.
2. That `/events/{id}` truly has `header.competitors[].team.id` / `.homeAway` directly under `header` (not nested one level deeper under `header.competitions[0]`, the way the fixtures list nests its own `competitors` under `competitions[0]`) — the two endpoints returned answers that look structurally different on this point and it's worth a real look before Task 3 depends on it.

- [ ] **Step 1: Add the service config entry**

In `config/services.php`, add, near the `la_liga_fantasy`/`la_liga_login` entries:

```php
    'worldcup26' => [
        'base_url' => env('WORLDCUP26_BASE_URL', 'https://worldcup26.ir/'),
    ],
```

- [ ] **Step 2: Write the request classes**

```php
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Worldcup26\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetFixturesRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $pageIndex = 1)
    {
    }

    public function resolveEndpoint(): string
    {
        return 'get/soccer/esp.1/fixtures';
    }

    /**
     * @return array<string, int|string>
     */
    protected function defaultQuery(): array
    {
        return [
            'status' => 'all',
            // The wire query key is "page" — the response body's own field
            // is "pageIndex" but that name is silently ignored as a query
            // param (confirmed against a live request). The constructor
            // parameter keeps the name "pageIndex" to match the response
            // body's field and this plan's declared getFixtures() signature.
            'page' => $this->pageIndex,
        ];
    }
}
```

**Post-Task-2 correction:** the query key above was originally written as `pageIndex` (a guess from documentation, matching the response body's own field name) — Task 2's implementer verified against a live request that the server silently ignores `pageIndex` as a query parameter and the real key is `page`. Fixed above to match what was actually shipped. Also verified live: `/events/{id}`'s `header.competitors[]` does NOT exist directly under `header` — it's nested under `header.competitions.0.competitors[]`, same pattern as the fixtures list. Neither Task 3 nor Task 5 as written below ends up depending on that path (Task 3 only calls `/fixtures`; Task 5 reads `rosters[].team.id` directly, never `header.competitors`), so this second finding turned out not to be load-bearing for this plan — noted here for anyone extending this connector later.

```php
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Worldcup26\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetEventRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $matchDataId)
    {
    }

    public function resolveEndpoint(): string
    {
        return "get/soccer/esp.1/events/{$this->matchDataId}";
    }
}
```

- [ ] **Step 3: Verify the two open questions from above against a real request**

Run, via `php artisan tinker`:

```php
$response = (new App\Http\Integrations\Worldcup26\Worldcup26Connector)->getFixtures(2);
dump($response->json('pageIndex'), count($response->json('events')));
```

(You'll need Step 4's connector written first — do this check after Step 4, before Step 6's commit.) Confirm `pageIndex` reports `2` and the events differ from page 1's. Then:

```php
$event = (new App\Http\Integrations\Worldcup26\Worldcup26Connector)->getEvent(401882926);
dump($event->json('header.competitors.0.team.id'), $event->json('header.competitors.0.homeAway'));
```

Confirm this returns a real team id and `"home"`/`"away"`, not `null`. If either check fails, adjust `GetFixturesRequest`'s query key or the JSON path assumptions in Tasks 3-4 accordingly — this is exactly why this step exists before those tasks are written against it.

- [ ] **Step 4: Write the connector**

```php
<?php

declare(strict_types=1);

namespace App\Http\Integrations\Worldcup26;

use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Requests\GetFixturesRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Connector;
use Saloon\Http\Response;
use Saloon\Traits\Plugins\HasTimeout;

class Worldcup26Connector extends Connector
{
    use HasTimeout;

    protected float $connectTimeout = 3;

    protected float $requestTimeout = 10;

    public function resolveBaseUrl(): string
    {
        return (string) config('services.worldcup26.base_url');
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getFixtures(int $pageIndex = 1): Response
    {
        return $this->send(new GetFixturesRequest($pageIndex));
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     */
    public function getEvent(int $matchDataId): Response
    {
        return $this->send(new GetEventRequest($matchDataId));
    }
}
```

- [ ] **Step 5: Write the two request unit tests**

Follow the existing pattern in `tests/Unit/Http/Integrations/LaLigaFantasy/GetPlayersRequestTest.php` (read it for the exact style — asserts on HTTP method and resolved endpoint/query via Saloon's test helpers, no real network call).

```php
<?php

use App\Http\Integrations\Worldcup26\Requests\GetFixturesRequest;
use Saloon\Enums\Method;
use Saloon\Http\PendingRequest;
use Saloon\Http\Connector as SaloonConnector;

test('requests all La Liga fixtures for the given page', function (): void {
    $request = new GetFixturesRequest(2);

    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->resolveEndpoint())->toBe('get/soccer/esp.1/fixtures');

    $pendingRequest = new PendingRequest(new class extends SaloonConnector {
        public function resolveBaseUrl(): string
        {
            return 'https://worldcup26.ir/';
        }
    }, $request);

    expect($pendingRequest->query()->all())->toBe(['status' => 'all', 'pageIndex' => 2]);
});

test('defaults to the first page', function (): void {
    $request = new GetFixturesRequest;

    $pendingRequest = new PendingRequest(new class extends \Saloon\Http\Connector {
        public function resolveBaseUrl(): string
        {
            return 'https://worldcup26.ir/';
        }
    }, $request);

    expect($pendingRequest->query()->all())->toBe(['status' => 'all', 'pageIndex' => 1]);
});
```

```php
<?php

use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use Saloon\Enums\Method;

test('requests a single match by its worldcup26 id', function (): void {
    $request = new GetEventRequest(401882926);

    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->resolveEndpoint())->toBe('get/soccer/esp.1/events/401882926');
});
```

If `PendingRequest`'s constructor signature in the installed Saloon version doesn't match what's written above, read `tests/Unit/Http/Integrations/LaLigaFantasy/GetFixturesRequestTest.php` (the existing La Liga Fantasy one, which also builds a query string) and match its exact instantiation pattern instead — that file is the authoritative example in this codebase, this plan's version is a best-effort reconstruction.

- [ ] **Step 6: Run the tests, then commit**

Run: `php artisan test --filter=Worldcup26`
Expected: PASS

```bash
git add app/Http/Integrations/Worldcup26 config/services.php tests/Unit/Http/Integrations/Worldcup26
git commit -m "feat: add Worldcup26Connector"
```

---

## Task 3: `season:link-match-data-fixtures` command

**Files:**
- Create: `app/Console/Commands/LinkMatchDataFixtures.php`
- Test: `tests/Feature/Console/Commands/LinkMatchDataFixturesTest.php`

**Interfaces:**
- Consumes: `Worldcup26Connector::getFixtures(int $pageIndex)`.
- Produces: sets `fixtures.match_data_id` for every current-season `Fixture` it can uniquely match.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Console\Commands\LinkMatchDataFixtures;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Http\Integrations\Worldcup26\Requests\GetFixturesRequest;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('links a fixture to its worldcup26 match id by team pair and date', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'date' => '2026-08-15',
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetFixturesRequest::class => MockResponse::make([
            'count' => 1,
            'pageIndex' => 1,
            'pageSize' => 100,
            'pageCount' => 1,
            'events' => [
                [
                    'id' => '401882926',
                    'date' => '2026-08-15T20:00Z',
                    'competitions' => [[
                        'competitors' => [
                            ['homeAway' => 'home', 'team' => ['id' => '83']],
                            ['homeAway' => 'away', 'team' => ['id' => '86']],
                        ],
                    ]],
                ],
            ],
        ]),
    ]));

    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(LinkMatchDataFixtures::class)
        ->expectsOutput('1 fixtures linked.')
        ->assertSuccessful();

    expect($fixture->refresh()->match_data_id)->toBe(401882926);
});

test('does not link when the same team pair has two same-day candidates', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'date' => '2026-08-15',
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetFixturesRequest::class => MockResponse::make([
            'count' => 2,
            'pageIndex' => 1,
            'pageSize' => 100,
            'pageCount' => 1,
            'events' => [
                [
                    'id' => '401882926',
                    'date' => '2026-08-15T20:00Z',
                    'competitions' => [['competitors' => [
                        ['homeAway' => 'home', 'team' => ['id' => '83']],
                        ['homeAway' => 'away', 'team' => ['id' => '86']],
                    ]]],
                ],
                [
                    'id' => '401882999',
                    'date' => '2026-08-15T22:00Z',
                    'competitions' => [['competitors' => [
                        ['homeAway' => 'home', 'team' => ['id' => '83']],
                        ['homeAway' => 'away', 'team' => ['id' => '86']],
                    ]]],
                ],
            ],
        ]),
    ]));

    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(LinkMatchDataFixtures::class)
        ->expectsOutput('0 fixtures linked.')
        ->assertSuccessful();

    expect($fixture->refresh()->match_data_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LinkMatchDataFixturesTest`
Expected: FAIL — class `App\Console\Commands\LinkMatchDataFixtures` not found.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:link-match-data-fixtures')]
#[Description('Link the current season fixtures to their worldcup26.ir match id')]
class LinkMatchDataFixtures extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $connector): int
    {
        $season = Season::current();
        $teamsByMatchDataId = Team::query()->whereNotNull('match_data_id')->get()->keyBy('match_data_id');

        /** @var array<int, array{matchDataId: int, homeTeamId: int, awayTeamId: int, date: string}> $remoteFixtures */
        $remoteFixtures = [];
        $pageIndex = 1;

        do {
            $page = $connector->getFixtures($pageIndex)->throw()->json();
            $events = is_array($page['events'] ?? null) ? $page['events'] : [];

            foreach ($events as $event) {
                $competitors = $event['competitions'][0]['competitors'] ?? null;

                if (!is_array($competitors)) {
                    continue;
                }

                $home = null;
                $away = null;

                foreach ($competitors as $competitor) {
                    if (($competitor['homeAway'] ?? null) === 'home') {
                        $home = (int) ($competitor['team']['id'] ?? 0);
                    } elseif (($competitor['homeAway'] ?? null) === 'away') {
                        $away = (int) ($competitor['team']['id'] ?? 0);
                    }
                }

                if ($home === null || $away === null || !isset($event['id'], $event['date'])) {
                    continue;
                }

                $remoteFixtures[] = [
                    'matchDataId' => (int) $event['id'],
                    'homeTeamId' => $home,
                    'awayTeamId' => $away,
                    'date' => (string) $event['date'],
                ];
            }

            $pageCount = (int) ($page['pageCount'] ?? 1);
            $pageIndex++;
        } while ($pageIndex <= $pageCount);

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNull('match_data_id')
            ->get();

        $linked = DB::transaction(function () use ($fixtures, $remoteFixtures, $teamsByMatchDataId): int {
            $linked = 0;

            foreach ($fixtures as $fixture) {
                $homeMatchDataId = $teamsByMatchDataId->firstWhere('id', $fixture->team_local_id)?->match_data_id;
                $awayMatchDataId = $teamsByMatchDataId->firstWhere('id', $fixture->team_guest_id)?->match_data_id;

                if ($homeMatchDataId === null || $awayMatchDataId === null) {
                    continue;
                }

                $candidates = array_filter(
                    $remoteFixtures,
                    fn (array $remote): bool => $remote['homeTeamId'] === $homeMatchDataId
                        && $remote['awayTeamId'] === $awayMatchDataId
                        && abs(CarbonImmutable::parse($remote['date'])->diffInDays($fixture->date, absolute: true)) <= 1,
                );

                if (count($candidates) !== 1) {
                    continue;
                }

                $fixture->update(['match_data_id' => reset($candidates)['matchDataId']]);
                $linked++;
            }

            return $linked;
        });

        $this->info($linked.' fixtures linked.');

        return self::SUCCESS;
    }
}
```

Note: `$teamsByMatchDataId->firstWhere('id', ...)` scans the keyed-by-`match_data_id` collection by its `id` attribute instead of using the key — this is intentional: we're looking up by *our* `teams.id` (the fixture's `team_local_id`/`team_guest_id`), not by `match_data_id`, so the collection's own key isn't the lookup key here. With only 20 teams this linear scan is negligible; don't rebuild a second collection keyed by `id` just to avoid it.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LinkMatchDataFixturesTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/LinkMatchDataFixtures.php tests/Feature/Console/Commands/LinkMatchDataFixturesTest.php
git commit -m "feat: add season:link-match-data-fixtures command"
```

---

## Task 4: `MatchDataPlayerMatcher` — the name-matching chain, tested in isolation

**Files:**
- Create: `app/Services/MatchDataPlayerMatcher.php`
- Test: `tests/Unit/Services/MatchDataPlayerMatcherTest.php`

**Interfaces:**
- Produces: `MatchDataPlayerMatcher::match(Collection<int, Player> $players, array<int, array{id: int, displayName: string}> $roster): array<int, int>` — maps `Player::id` to a worldcup26 athlete id for every player it could resolve uniquely. Never guesses: any player or roster entry with more than one remaining candidate at a given rule stays unresolved at that rule (a later, looser rule may still resolve it if it becomes unambiguous once tighter matches are removed).

This class has no dependency on `Player`'s persistence — it only reads `$player->id` and `$player->nickname` — so it's testable with plain `Player::factory()->make(...)` instances (no DB needed) or hand-built stand-ins; prefer `make()` (not `create()`) since nothing here touches the database and this test should be fast and isolated.

- [ ] **Step 1: Write the failing tests — one per rule, plus tie-breaking and per-team scoping**

```php
<?php

use App\Models\Player;
use App\Services\MatchDataPlayerMatcher;
use Illuminate\Support\Collection;

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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=MatchDataPlayerMatcherTest`
Expected: FAIL — class `App\Services\MatchDataPlayerMatcher` not found.

- [ ] **Step 3: Write the matcher**

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Player;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class MatchDataPlayerMatcher
{
    /**
     * Resolves each player against the worldcup26 roster of their own team,
     * trying rules from strictest to loosest. A rule only commits a match
     * when it finds exactly one remaining candidate on both sides — ambiguous
     * cases are left for the next (looser) rule, and if no rule ever narrows
     * them to one, they're left unresolved for manual review.
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, array{id: int, displayName: string}>  $roster
     * @return array<int, int> Player::id => worldcup26 athlete id
     */
    public function match(Collection $players, array $roster): array
    {
        /** @var Collection<int, Player> $unresolvedPlayers */
        $unresolvedPlayers = $players->keyBy('id');
        /** @var Collection<int, array{id: int, displayName: string}> $availableRoster */
        $availableRoster = (new Collection($roster))->keyBy('id');

        /** @var array<int, int> $resolved */
        $resolved = [];

        $rules = [
            $this->exactMatch(...),
            $this->surnameMatch(...),
            $this->firstNamePrefixMatch(...),
            $this->initialAndSurnameMatch(...),
        ];

        foreach ($rules as $rule) {
            foreach ($unresolvedPlayers->all() as $playerId => $player) {
                $nickname = $this->fold($player->nickname);

                $candidates = $availableRoster->filter(
                    fn (array $entry): bool => $rule($nickname, $this->fold($entry['displayName'])),
                );

                if ($candidates->count() !== 1) {
                    continue;
                }

                $matchDataId = $candidates->keys()->first();
                $resolved[$playerId] = $matchDataId;
                $unresolvedPlayers->forget($playerId);
                $availableRoster->forget($matchDataId);
            }
        }

        return $resolved;
    }

    private function exactMatch(string $nickname, string $fullName): bool
    {
        return $nickname === $fullName;
    }

    private function surnameMatch(string $nickname, string $fullName): bool
    {
        $words = explode(' ', $nickname);
        $surname = end($words);

        return $surname !== '' && in_array($surname, explode(' ', $fullName), true);
    }

    private function firstNamePrefixMatch(string $nickname, string $fullName): bool
    {
        $firstName = (string) preg_replace('/\s*(jr\.?|junior)$/', '', $nickname);
        $firstNameFirstWord = explode(' ', $firstName)[0] ?? '';
        $fullNameFirstWord = explode(' ', $fullName)[0] ?? '';

        return $firstNameFirstWord !== '' && str_starts_with($fullNameFirstWord, $firstNameFirstWord);
    }

    private function initialAndSurnameMatch(string $nickname, string $fullName): bool
    {
        if (preg_match('/^([a-z])\.?\s+(.+)$/', $nickname, $matches) !== 1) {
            return false;
        }

        [, $initial, $surname] = $matches;
        $fullNameWords = explode(' ', $fullName);

        return ($fullNameWords[0][0] ?? '') === $initial && in_array($surname, $fullNameWords, true);
    }

    private function fold(string $value): string
    {
        return Str::of($value)->ascii()->lower()->trim()->value();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=MatchDataPlayerMatcherTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/MatchDataPlayerMatcher.php tests/Unit/Services/MatchDataPlayerMatcherTest.php
git commit -m "feat: add MatchDataPlayerMatcher name-matching chain"
```

---

## Task 5: `season:link-match-data-players` command

**Files:**
- Create: `app/Console/Commands/LinkMatchDataPlayers.php`
- Test: `tests/Feature/Console/Commands/LinkMatchDataPlayersTest.php`

**Interfaces:**
- Consumes: `Worldcup26Connector::getEvent(int $matchDataId)`, `MatchDataPlayerMatcher::match(...)`.
- Produces: sets `players.match_data_id` for every player it can resolve; prints unresolved player nicknames (grouped by team) for manual review.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Console\Commands\LinkMatchDataPlayers;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('links players by fetching the roster of each already-linked fixture', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
    ]);
    $homePlayer = Player::factory()->create(['team_id' => $home->id, 'nickname' => 'Sivera']);
    $awayPlayer = Player::factory()->create(['team_id' => $away->id, 'nickname' => 'Bellingham']);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make([
            'rosters' => [
                [
                    'team' => ['id' => 83],
                    'roster' => [
                        ['athlete' => ['id' => 5001, 'displayName' => 'Antonio Sivera'], 'starter' => true],
                    ],
                ],
                [
                    'team' => ['id' => 86],
                    'roster' => [
                        ['athlete' => ['id' => 5002, 'displayName' => 'Jude Bellingham'], 'starter' => true],
                    ],
                ],
            ],
        ]),
    ]));

    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('2 players linked.')
        ->assertSuccessful();

    expect($homePlayer->refresh()->match_data_id)->toBe(5001)
        ->and($awayPlayer->refresh()->match_data_id)->toBe(5002);
});

test('reports unresolved players without linking them', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
    ]);
    $unmatchable = Player::factory()->create(['team_id' => $home->id, 'nickname' => 'Zzyzx']);
    Player::factory()->create(['team_id' => $away->id, 'nickname' => 'Bellingham']);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make([
            'rosters' => [
                ['team' => ['id' => 83], 'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Antonio Sivera'], 'starter' => true],
                ]],
                ['team' => ['id' => 86], 'roster' => [
                    ['athlete' => ['id' => 5002, 'displayName' => 'Jude Bellingham'], 'starter' => true],
                ]],
            ],
        ]),
    ]));

    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(LinkMatchDataPlayers::class)
        ->expectsOutput('1 players linked.')
        ->expectsOutputToContain('Zzyzx')
        ->assertSuccessful();

    expect($unmatchable->refresh()->match_data_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LinkMatchDataPlayersTest`
Expected: FAIL — class `App\Console\Commands\LinkMatchDataPlayers` not found.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use App\Services\MatchDataPlayerMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:link-match-data-players')]
#[Description('Link current season players to their worldcup26.ir athlete id, via already-linked fixtures\' rosters')]
class LinkMatchDataPlayers extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $connector, MatchDataPlayerMatcher $matcher): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->get();

        $linked = 0;
        $unresolved = [];

        foreach ($fixtures as $fixture) {
            $event = $connector->getEvent($fixture->match_data_id)->throw()->json();
            $rosters = is_array($event['rosters'] ?? null) ? $event['rosters'] : [];

            foreach ($rosters as $rosterEntry) {
                $teamMatchDataId = (int) ($rosterEntry['team']['id'] ?? 0);
                $team = Team::query()->where('match_data_id', $teamMatchDataId)->first();

                if ($team === null) {
                    continue;
                }

                $players = Player::query()
                    ->where('team_id', $team->id)
                    ->whereNull('match_data_id')
                    ->get();

                if ($players->isEmpty()) {
                    continue;
                }

                $roster = collect($rosterEntry['roster'] ?? [])
                    ->filter(fn ($entry): bool => is_array($entry) && isset($entry['athlete']['id'], $entry['athlete']['displayName']))
                    ->map(fn (array $entry): array => [
                        'id' => (int) $entry['athlete']['id'],
                        'displayName' => (string) $entry['athlete']['displayName'],
                    ])
                    ->values()
                    ->all();

                $matches = $matcher->match($players, $roster);

                DB::transaction(function () use ($players, $matches, &$linked, &$unresolved): void {
                    foreach ($players as $player) {
                        if (isset($matches[$player->id])) {
                            $player->update(['match_data_id' => $matches[$player->id]]);
                            $linked++;
                        } else {
                            $unresolved[] = "{$player->nickname} (team #{$player->team_id})";
                        }
                    }
                });
            }
        }

        $this->info($linked.' players linked.');

        if ($unresolved !== []) {
            $this->warn('Unresolved — needs manual review: '.implode(', ', $unresolved));
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LinkMatchDataPlayersTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/LinkMatchDataPlayers.php tests/Feature/Console/Commands/LinkMatchDataPlayersTest.php
git commit -m "feat: add season:link-match-data-players command"
```

---

## Task 6: `SyncCurrentSeasonFixtures` stops writing state/score

**Files:**
- Modify: `app/Console/Commands/SyncCurrentSeasonFixtures.php`
- Modify: `tests/Feature/Console/Commands/SyncCurrentSeasonFixturesTest.php`

**Interfaces:**
- Produces: same command, same `fantasy_id`/`week_number`/`date`/team-pair writes as before; `state`, `local_score`, `guest_score` are no longer part of the `updateOrCreate` payload for existing fixtures (a brand-new fixture row still needs *some* initial value for these NOT NULL-with-default columns — `Fixture`'s own `$attributes` default already provides `state => FixtureState::Scheduled`, and `local_score`/`guest_score` are nullable, so simply omitting them from the payload is enough; Eloquent's `updateOrCreate` only sets the columns you pass, but a *new* row still needs the model's defaults to apply — confirm this holds in Step 4, since `updateOrCreate`'s create-path does apply the model's `$attributes` defaults for anything not explicitly passed).

- [ ] **Step 1: Update the existing test's assertions**

Read the current `tests/Feature/Console/Commands/SyncCurrentSeasonFixturesTest.php` in full first — the test whose assertions currently include (per the earlier grep) lines around `->and($fixture->state)->toBe(FixtureState::Finished)`, `->and($fixture->local_score)->toBe(2)`, `->and($fixture->guest_score)->toBe(1)`. Since the command will stop writing these, that specific test needs to change its premise: instead of asserting the synced values equal what the (still-present, unused) mock response's `matchState`/`localScore`/`visitorScore` fields say, assert that an **existing** fixture's `state`/`local_score`/`guest_score` are **left untouched** by a sync run — pre-set the fixture with known values before running the command, then assert those exact values are unchanged afterward (rather than asserting they equal the mock's now-ignored fields). Keep the rest of that test's setup and its `fantasy_id`/`week_number`/`date`/team assertions as they are.

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SyncCurrentSeasonFixturesTest`
Expected: FAIL — the command still writes `state`/`local_score`/`guest_score`, so the "left untouched" assertion doesn't hold yet.

- [ ] **Step 3: Update the command**

In `app/Console/Commands/SyncCurrentSeasonFixtures.php`, remove `'local_score'`, `'guest_score'`, and `'state'` from the `$fixtures[]` array built in the loop — keep `'fantasy_id'`, `'season_id'`, `'week_number'`, `'date'`, `'team_local_id'`, `'team_guest_id'`. The `updateOrCreate` call itself doesn't change.

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=SyncCurrentSeasonFixturesTest`
Expected: PASS. If a *newly-created* fixture in some other test ends up with an unexpected `state`, confirm `Fixture`'s `protected $attributes = ['state' => FixtureState::Scheduled];` default is still applying — it should, since `updateOrCreate`'s create-path instantiates a fresh model with its defaults before filling in only the given attributes.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/SyncCurrentSeasonFixtures.php tests/Feature/Console/Commands/SyncCurrentSeasonFixturesTest.php
git commit -m "refactor: SyncCurrentSeasonFixtures stops writing state/score"
```

---

## Task 7: Full-suite verification

**Files:** none (verification only).

- [ ] **Step 1: Run the whole test suite**

Run: `php artisan test`
Expected: all green.

- [ ] **Step 2: Static analysis and formatting**

Run: `vendor/bin/phpstan analyse --memory-limit=2G`
Run: `vendor/bin/pint --parallel`
Expected: 0 errors; no diffs (or trivial ones — commit those separately if any appear).

- [ ] **Step 3: Final commit (only if Step 2's pint run produced changes)**

```bash
git add -A
git commit -m "style: pint formatting after match data linking"
```
