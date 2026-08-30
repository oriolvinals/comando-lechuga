# Sync Commands Refactor Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite `SyncCurrentSeasonTeams` to be worldcup26-first (teams + fixtures both come cheaply from the same paginated fixtures list, unlike players), delete the now-redundant `LinkMatchDataTeams`, extract the match-data sync logic already in `SyncLiveSeasonMatchData` into a shared concern so two new commands (`SyncCurrentSeasonMatchData`, `SyncSeasonMatchDataBackfill`) can reuse it at wider time windows, and have that shared logic capture unresolved players' real names plus fill `fixture_lineups.fantasy_points`/`.fantasy_stats` from LaLiga Fantasy's live scores — restoring, in one integrated pass, what the deleted `SyncLiveSeasonPlayerScores`/`SyncLiveSeasonPlayerScoreStats` commands used to do separately.

**Architecture:** Laravel 12 command classes calling two existing Saloon connectors (`Worldcup26Connector`, `LaLigaFantasyConnector`) — no new HTTP client code needed, both already have every method this plan uses. Fixtures/players/most Fantasy-only domains (activity, standing, market, player markets, manager players/lineups, player photos) are explicitly unchanged — this plan touches only teams and match-data (fixture events/lineups/fantasy scores).

**Tech Stack:** Laravel 12 + Pest (backend only — no frontend changes in this plan).

**Spec:** `docs/superpowers/specs/2026-08-30-sync-commands-refactor-design.md`

## Global Constraints

- Only `SyncCurrentSeasonTeams` and the match-data sync trio change. `SyncCurrentSeasonWeek`, `SyncCurrentSeasonPlayers`, `LinkMatchDataPlayers`, `SyncCurrentSeasonPlayerPhotos`, `SyncCurrentSeasonActivity`, `SyncCurrentSeasonStanding`, `SyncCurrentSeasonMarket`, `SyncCurrentSeasonPlayerMarkets`, `SyncCurrentSeasonFixtures`, `LinkMatchDataFixtures`, `SyncCurrentSeasonManagerPlayers`, `SyncCurrentSeasonManagerLineups` are explicitly out of scope — do not touch them.
- `MatchDataPlayerMatcher` is explicitly out of scope — players stay Fantasy-first, the matcher's existing nickname(short)-vs-fullName(long) direction is correct as-is and must not be generalized or reversed.
- Team logo stays 100% Fantasy-sourced even though worldcup26 also has one — no new asset-download code for worldcup26 team logos.
- `Fixture` creation stays 100% Fantasy — worldcup26 has no `week_number` anywhere, so it cannot be the creator. Do not touch `SyncCurrentSeasonFixtures` or `LinkMatchDataFixtures`.
- The three match-data sync commands (`SyncLiveSeasonMatchData`, `SyncCurrentSeasonMatchData`, `SyncSeasonMatchDataBackfill`) must share one implementation of the actual sync logic (fixture state/score, lineups, events, fantasy points/stats) — differ only in which fixtures they select and how often they're scheduled. No copy-pasted sync logic between them.
- `fixture_lineups.stats` (worldcup26 raw counters) and `fixture_lineups.fantasy_stats` (Fantasy's point breakdown) stay two separate JSON columns — this plan only ever writes `fantasy_points`/`fantasy_stats`, never touches the existing `stats` column's population logic.

---

### Task 1: `teams.match_data_id` becomes `NOT NULL`

**Files:**
- Create: `database/migrations/2026_08_31_090000_make_teams_match_data_id_not_null.php`
- Test: `tests/Feature/Models/TeamTest.php` (check with `Glob` whether it exists; add to it if so, create if not)

**Interfaces:**
- Produces: `teams.match_data_id` is now `NOT NULL` — every `Team` row must have one from creation onward, since Task 2 makes worldcup26 the creator.

**Ordering note:** this migration must run AFTER Task 2's `SyncCurrentSeasonTeams` rewrite ships in the same deploy (a `NOT NULL` constraint added before any code guarantees the column is always set would be fine on an empty/wiped table, but is logically Task 2's precondition) — for this plan's purposes both land in the same branch before any deploy, so ordering between the two tasks doesn't matter for correctness, only that both exist before this branch ships.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Team;
use Illuminate\Database\QueryException;

test('match_data_id cannot be null', function (): void {
    Team::factory()->create(['match_data_id' => null]);
})->throws(QueryException::class);
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=TeamTest`
Expected: FAIL — `match_data_id` is currently nullable, no exception is thrown.

- [ ] **Step 3: Write the migration**

Check `composer.json` for `doctrine/dbal` first — it was removed from this project and verified unnecessary for `->change()` migrations on this Laravel version (do not reinstall it; see `docs/superpowers/plans/2026-08-29-worldcup26-primary-source.md` Task 3 for the verified reasoning if you need it).

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
            $table->unsignedInteger('match_data_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedInteger('match_data_id')->nullable()->change();
        });
    }
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=TeamTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: FAIL on any existing test that creates a `Team` without `match_data_id` — check `database/factories/TeamFactory.php` first: if its `definition()` doesn't already set `match_data_id`, add `'match_data_id' => $this->faker->unique()->numberBetween(1, 99999),` to it before running the suite, since every `Team::factory()->create()` call across the whole test suite now needs one.

- [ ] **Step 6: Run the full test suite again after the factory fix**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_31_090000_make_teams_match_data_id_not_null.php database/factories/TeamFactory.php tests/Feature/Models/TeamTest.php
git commit -m "feat: make teams.match_data_id NOT NULL — worldcup26 is now the team creator"
```

---

### Task 2: Rewrite `SyncCurrentSeasonTeams` — worldcup26-first, Fantasy enrich-only

**Files:**
- Modify: `app/Console/Commands/SyncCurrentSeasonTeams.php`
- Test: `tests/Feature/Console/Commands/SyncCurrentSeasonTeamsTest.php`

**Interfaces:**
- Consumes: `Worldcup26Connector::getFixtures(int $pageIndex)` (already exists), `LaLigaFantasyConnector::getTeamInfo()` (already exists, currently used by this same command).
- Produces: `Team` rows created/updated by `match_data_id` from worldcup26, with `fantasy_id` backfilled via the existing hardcoded `TEAM_MAP` (same map `LinkMatchDataTeams` used, inverted), then enriched (never created) with `main_name`/`slug`/`logo` from Fantasy's team feed.

- [ ] **Step 1: Write the failing tests — replace the whole test file**

Read the CURRENT `tests/Feature/Console/Commands/SyncCurrentSeasonTeamsTest.php` first to see its existing helper functions/mocking style (it already mocks `LaLigaFantasyConnector::getTeamInfo()` and `getAsset()` for the logo download — reuse that exact mocking pattern for the Fantasy-enrich half below). Replace the file's tests with:

```php
<?php

use App\Console\Commands\SyncCurrentSeasonTeams;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetTeamInfoRequest;
use App\Http\Integrations\Worldcup26\Requests\GetFixturesRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

function worldcup26FixturesPayload(array $events, int $pageIndex = 1, int $pageCount = 1): array
{
    return [
        'league' => 'esp.1',
        'count' => count($events),
        'pageIndex' => $pageIndex,
        'pageSize' => 25,
        'pageCount' => $pageCount,
        'filters' => [],
        'events' => $events,
    ];
}

function worldcup26FixtureEvent(int $localTeamId, string $localTeamName, string $localShort, int $guestTeamId, string $guestTeamName, string $guestShort): array
{
    return [
        'id' => (string) random_int(400000000, 499999999),
        'season' => ['year' => 2026, 'type_id' => '14357', 'slug' => '2026-27-laliga', 'name' => ''],
        'competitions' => [[
            'competitors' => [
                ['homeAway' => 'home', 'team' => ['id' => (string) $localTeamId, 'name' => $localTeamName, 'shortDisplayName' => $localShort]],
                ['homeAway' => 'away', 'team' => ['id' => (string) $guestTeamId, 'name' => $guestTeamName, 'shortDisplayName' => $guestShort]],
            ],
        ]],
    ];
}

test('creates teams from worldcup26 fixtures, backfills fantasy_id from the hardcoded map', function (): void {
    $season = Season::factory()->create(['match_data_season_slug' => '2026-27-laliga']);

    $event = worldcup26FixtureEvent(83, 'Real Madrid', 'RMA', 86, 'Villarreal', 'VIL');

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetFixturesRequest::class => MockResponse::make(worldcup26FixturesPayload([$event])),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamInfoRequest::class => MockResponse::make([]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeams::class)->assertSuccessful();

    $realMadrid = Team::query()->where('match_data_id', 83)->sole();
    expect($realMadrid->name)->toBe('Real Madrid')
        ->and($realMadrid->short_name)->toBe('RMA')
        ->and($realMadrid->fantasy_id)->toBe(4); // TEAM_MAP: fantasy_id 4 => worldcup26 id 83

    $villarreal = Team::query()->where('match_data_id', 86)->sole();
    expect($villarreal->fantasy_id)->not->toBeNull();
});

test('filters out events from a different season by match_data_season_slug', function (): void {
    Season::factory()->create(['match_data_season_slug' => '2026-27-laliga']);

    $currentSeasonEvent = worldcup26FixtureEvent(83, 'Real Madrid', 'RMA', 86, 'Villarreal', 'VIL');
    $otherSeasonEvent = worldcup26FixtureEvent(999, 'Old Team A', 'OTA', 998, 'Old Team B', 'OTB');
    $otherSeasonEvent['season']['slug'] = '2025-26-laliga';

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetFixturesRequest::class => MockResponse::make(worldcup26FixturesPayload([$currentSeasonEvent, $otherSeasonEvent])),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamInfoRequest::class => MockResponse::make([]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeams::class)->assertSuccessful();

    expect(Team::query()->where('match_data_id', 999)->exists())->toBeFalse();
});

test('enriches an existing team by fantasy_id, never creates a new row', function (): void {
    $season = Season::factory()->create(['match_data_season_slug' => '2026-27-laliga']);
    $existing = Team::factory()->create(['match_data_id' => 83, 'fantasy_id' => 4, 'main_name' => '', 'slug' => '', 'logo' => '']);

    $event = worldcup26FixtureEvent(83, 'Real Madrid', 'RMA', 86, 'Villarreal', 'VIL');

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetFixturesRequest::class => MockResponse::make(worldcup26FixturesPayload([$event])),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamInfoRequest::class => MockResponse::make([
            ['id' => 4, 'mainName' => 'Real Madrid CF', 'name' => 'Real Madrid', 'slug' => 'real-madrid', 'shortName' => 'RMA', 'badgeColor' => null],
        ]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeams::class)->assertSuccessful();

    expect(Team::query()->count())->toBe(1)
        ->and($existing->fresh()->main_name)->toBe('Real Madrid CF')
        ->and($existing->fresh()->slug)->toBe('real-madrid');
});
```

The `TEAM_MAP` this test relies on (fantasy_id 4 → worldcup26 id 83) is the exact same map already in `app/Console/Commands/LinkMatchDataTeams.php` (being deleted in Task 3) — Step 3 below copies it verbatim into the new command.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SyncCurrentSeasonTeamsTest`
Expected: FAIL — the command doesn't fetch from worldcup26 yet.

- [ ] **Step 3: Rewrite the command**

Read the CURRENT `app/Console/Commands/SyncCurrentSeasonTeams.php` and `app/Console/Commands/LinkMatchDataTeams.php` in full before writing this — you need the exact current `storeBadge()` logic (kept, unchanged, still used by the Fantasy-enrich half) and the exact `TEAM_MAP` array (copied verbatim from `LinkMatchDataTeams`).

Replace `app/Console/Commands/SyncCurrentSeasonTeams.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-teams')]
#[Description('Synchronize the current season teams from worldcup26.ir, enriched with Fantasy data')]
class SyncCurrentSeasonTeams extends Command
{
    /**
     * fantasy_id => worldcup26.ir team id — validated 1:1 against real data
     * (same mapping previously used by the now-deleted LinkMatchDataTeams).
     *
     * @var array<int, int>
     */
    private const array TEAM_MAP = [
        2 => 1068,
        3 => 93,
        4 => 83,
        5 => 244,
        6 => 85,
        7 => 3751,
        8 => 88,
        9 => 2922,
        11 => 1538,
        12 => 99,
        13 => 97,
        14 => 101,
        15 => 86,
        16 => 89,
        17 => 243,
        18 => 94,
        20 => 102,
        21 => 96,
        26 => 90,
        49 => 87,
    ];

    /** @var array<int, int> worldcup26 id => fantasy_id, the inverse of TEAM_MAP */
    private array $matchDataIdToFantasyId;

    public function __construct()
    {
        parent::__construct();

        $this->matchDataIdToFantasyId = array_flip(self::TEAM_MAP);
    }

    /**
     * @throws Throwable
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    public function handle(Worldcup26Connector $worldcup26Connector, LaLigaFantasyConnector $fantasyConnector): int
    {
        $season = Season::current();

        $created = DB::transaction(fn (): int => $this->syncFromWorldcup26($worldcup26Connector, $season));

        $enriched = DB::transaction(fn (): int => $this->enrichFromFantasy($fantasyConnector));

        $this->info("{$created} teams synced from worldcup26, {$enriched} enriched from Fantasy.");

        return self::SUCCESS;
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    private function syncFromWorldcup26(Worldcup26Connector $connector, Season $season): int
    {
        /** @var array<int, array{name: string, shortName: string}> $teamsById */
        $teamsById = [];
        $pageIndex = 1;

        do {
            $response = $connector->getFixtures($pageIndex)->throw()->json();
            $events = is_array($response['events'] ?? null) ? $response['events'] : [];
            $pageCount = (int) ($response['pageCount'] ?? 1);

            foreach ($events as $event) {
                if (!is_array($event) || ($event['season']['slug'] ?? null) !== $season->match_data_season_slug) {
                    continue;
                }

                $competitors = $event['competitions'][0]['competitors'] ?? [];

                if (!is_array($competitors)) {
                    continue;
                }

                foreach ($competitors as $competitor) {
                    $team = $competitor['team'] ?? null;

                    if (!is_array($team) || !isset($team['id'])) {
                        continue;
                    }

                    $matchDataId = (int) $team['id'];
                    $teamsById[$matchDataId] = [
                        'name' => (string) ($team['name'] ?? ''),
                        'shortName' => (string) ($team['shortDisplayName'] ?? ''),
                    ];
                }
            }

            $pageIndex++;
        } while ($pageIndex <= $pageCount);

        $synced = 0;

        foreach ($teamsById as $matchDataId => $teamData) {
            Team::query()->updateOrCreate(
                ['match_data_id' => $matchDataId],
                [
                    'name' => $teamData['name'],
                    'short_name' => $teamData['shortName'],
                    'fantasy_id' => $this->matchDataIdToFantasyId[$matchDataId] ?? null,
                ],
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    private function enrichFromFantasy(LaLigaFantasyConnector $connector): int
    {
        $enriched = 0;

        foreach ($connector->getTeamInfo()->throw()->json() as $teamData) {
            $fantasyId = (int) $teamData['id'];
            $team = Team::query()->where('fantasy_id', $fantasyId)->first();

            if ($team === null) {
                continue;
            }

            $badgeColor = $teamData['badgeColor'] ?? null;

            $team->update([
                'main_name' => (string) $teamData['mainName'],
                'name' => (string) $teamData['name'],
                'slug' => (string) $teamData['slug'],
                'short_name' => (string) $teamData['shortName'],
                'logo' => $this->storeBadge($connector, $fantasyId, is_string($badgeColor) ? $badgeColor : null),
            ]);

            $enriched++;
        }

        return $enriched;
    }

    /**
     * @throws FatalRequestException
     * @throws Throwable
     * @throws RequestException
     */
    private function storeBadge(LaLigaFantasyConnector $connector, int $fantasyId, ?string $badgeUrl): string
    {
        if ($badgeUrl === null) {
            return '';
        }

        $path = "images/team/{$fantasyId}.png";
        $contents = $connector->getAsset($badgeUrl)->throw()->body();
        $disk = Storage::disk('public');

        if (!$disk->exists($path) || !hash_equals(hash('sha256', $disk->get($path)), hash('sha256', $contents))) {
            $disk->put($path, $contents);
        }

        return $path;
    }
}
```

Note: `enrichFromFantasy()` overwrites `name`/`short_name` with Fantasy's own values even though `syncFromWorldcup26()` already set them from worldcup26 — this is deliberate: Fantasy's naming is what's shown throughout the rest of the app today (unchanged from before this task), so the worldcup26-sourced `name`/`short_name` set in the first pass is only a placeholder until a team's `fantasy_id` is known and the second pass overwrites it with Fantasy's version. A team with no `fantasy_id` match (not in `TEAM_MAP`, or a data anomaly) permanently keeps the worldcup26-sourced name — acceptable, matches the spec's "en la práctica siempre habrá fantasy_id porque el mapa cubre los 20 equipos" assumption.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncCurrentSeasonTeamsTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions (this command is called by nothing else directly, only scheduled).

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncCurrentSeasonTeams.php tests/Feature/Console/Commands/SyncCurrentSeasonTeamsTest.php
git commit -m "feat: rewrite SyncCurrentSeasonTeams to be worldcup26-first, Fantasy enrich-only"
```

---

### Task 3: Delete `LinkMatchDataTeams`

**Files:**
- Delete: `app/Console/Commands/LinkMatchDataTeams.php`
- Delete: `tests/Feature/Console/Commands/LinkMatchDataTeamsTest.php`

**Interfaces:**
- Removes: `season:link-match-data-teams` — redundant now that Task 2's `SyncCurrentSeasonTeams` does the same `fantasy_id` backfill using the same map, in the same command that creates the row.

- [ ] **Step 1: Confirm nothing else references it**

Run (via `Grep`, not shell): search the whole repo for `LinkMatchDataTeams`. Expected matches: only the command file and its test (both being deleted) — `bootstrap/app.php` never scheduled it (confirmed when it was built), so there's no schedule entry to remove.

- [ ] **Step 2: Delete the files**

```bash
git rm app/Console/Commands/LinkMatchDataTeams.php tests/Feature/Console/Commands/LinkMatchDataTeamsTest.php
```

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "chore: delete LinkMatchDataTeams — redundant, SyncCurrentSeasonTeams now does the same fantasy_id backfill"
```

---

### Task 4: `fixture_lineups.unresolved_name`

**Files:**
- Create: `database/migrations/2026_08_31_090100_add_unresolved_name_to_fixture_lineups_table.php`
- Modify: `app/Models/FixtureLineup.php`
- Modify: `database/factories/FixtureLineupFactory.php`
- Test: `tests/Feature/Models/FixtureLineupTest.php`

**Interfaces:**
- Produces: `FixtureLineup.unresolved_name: string|null` — set to the worldcup26 athlete's `displayName` when `player_id` is null (an unresolved roster entry), left null for a resolved player.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Models/FixtureLineupTest.php` (create it first with a `<?php` + `use App\Models\FixtureLineup;` header if it doesn't already exist — check with `Glob`):

```php
test('stores the worldcup26 display name for an unresolved lineup entry', function (): void {
    $lineup = FixtureLineup::factory()->create([
        'player_id' => null,
        'unresolved_name' => 'Unknown Player',
    ]);

    expect($lineup->unresolved_name)->toBe('Unknown Player');
});

test('unresolved_name defaults to null', function (): void {
    $lineup = FixtureLineup::factory()->create();

    expect($lineup->unresolved_name)->toBeNull();
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=FixtureLineupTest`
Expected: FAIL — `unresolved_name` isn't a column yet.

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
            $table->string('unresolved_name')->nullable()->after('player_id');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->dropColumn('unresolved_name');
        });
    }
};
```

- [ ] **Step 4: Update the `FixtureLineup` model**

In `app/Models/FixtureLineup.php`: add `@property-read string|null $unresolved_name` to the docblock (after the `$player_id` line), add `'unresolved_name'` to the `#[Fillable(...)]` array (after `'player_id'`), add `'unresolved_name' => 'string'` to `casts()`.

- [ ] **Step 5: Update `FixtureLineupFactory`**

In `database/factories/FixtureLineupFactory.php`, add `'unresolved_name' => null,` to `definition()`'s returned array (after `'player_id' => Player::factory(),`).

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=FixtureLineupTest`
Expected: PASS

- [ ] **Step 7: Run the full test suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_31_090100_add_unresolved_name_to_fixture_lineups_table.php app/Models/FixtureLineup.php database/factories/FixtureLineupFactory.php tests/Feature/Models/FixtureLineupTest.php
git commit -m "feat: add fixture_lineups.unresolved_name so the frontend can show a real name instead of 'No vinculado'"
```

---

### Task 5: Extract match-data sync logic into a shared concern, capture `unresolved_name`

**Files:**
- Create: `app/Console/Commands/Concerns/SyncsMatchData.php`
- Modify: `app/Console/Commands/SyncLiveSeasonMatchData.php`
- Modify: `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`

**Interfaces:**
- Produces: `SyncsMatchData` trait with a `syncMatchDataForFixtures(Collection<int, Fixture> $fixtures): array{synced: int, unresolved: list<string>}` method — every method currently private on `SyncLiveSeasonMatchData` (`syncFixture`, `scoreFor`, `formationFor`, `syncLineups`, `createUnresolvedLineup`, `upsertLineup`, `subMinute`, `minuteFromClock`, `syncEvents`, `eventType`) moves into this trait unchanged except `createUnresolvedLineup()`, which now also sets `unresolved_name`. `SyncLiveSeasonMatchData` becomes a thin command that selects its fixtures (same date-window logic as today, unchanged) and delegates to the trait.
- Consumes: `Worldcup26Connector::getEvent()` (unchanged, already used).

This is a pure refactor for this task — no behavior change to `SyncLiveSeasonMatchData` itself except the new `unresolved_name` capture. Tasks 7 and 8 are what actually add the two new commands that reuse this trait.

- [ ] **Step 1: Write the failing test for `unresolved_name`**

Append to `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php` (it already has a `liveMatchEventPayload()` helper at the top — reuse it):

```php
test('stores the worldcup26 display name for an unresolved lineup entry', function (): void {
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
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5', 'stats' => []],
                ],
            ],
        ],
    ]);

    $connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $connector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $unresolvedRow = FixtureLineup::query()->whereNull('player_id')->where('fixture_id', $fixture->id)->sole();
    expect($unresolvedRow->unresolved_name)->toBe('Unknown Player');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: FAIL — `unresolved_name` is never set.

- [ ] **Step 3: Create the trait**

Create `app/Console/Commands/Concerns/SyncsMatchData.php` with the exact content of `SyncLiveSeasonMatchData`'s current private methods, moved as-is, EXCEPT: rename the entry point to a public method `syncMatchDataForFixtures()`, and add `'unresolved_name' => (string) ($rosterPlayer['athlete']['displayName'] ?? ''),` to `createUnresolvedLineup()`'s create array.

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

trait SyncsMatchData
{
    /** @var list<string> */
    private const array KNOWN_WORLDCUP26_STATUS_NAMES = [
        'STATUS_SCHEDULED',
        'STATUS_FIRST_HALF',
        'STATUS_HALFTIME',
        'STATUS_SECOND_HALF',
        'STATUS_FULL_TIME',
    ];

    /**
     * @param  Collection<int, Fixture>  $fixtures
     * @return array{synced: int, unresolved: list<string>}
     *
     * @throws Throwable
     */
    private function syncMatchDataForFixtures(Collection $fixtures, \App\Http\Integrations\Worldcup26\Worldcup26Connector $connector): array
    {
        $synced = 0;
        $unresolved = [];

        foreach ($fixtures as $fixture) {
            try {
                $event = $connector->getEvent($fixture->match_data_id)->throw()->json();
            } catch (FatalRequestException|RequestException|JsonException $exception) {
                Log::warning("Failed to sync match data for fixture {$fixture->id}: {$exception->getMessage()}");

                continue;
            }

            DB::transaction(function () use ($fixture, $event, &$unresolved): void {
                $this->syncFixture($fixture, $event);
                $unresolved = [...$unresolved, ...$this->syncLineups($fixture, $event)];
                $this->syncEvents($fixture, $event);
            });

            $synced++;
        }

        return ['synced' => $synced, 'unresolved' => $unresolved];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function syncFixture(Fixture $fixture, array $event): void
    {
        $competition = $event['header']['competitions'][0] ?? [];
        $statusName = (string) ($competition['status']['type']['name'] ?? '');
        $competitors = is_array($competition['competitors'] ?? null) ? array_values($competition['competitors']) : [];
        $rosters = is_array($event['rosters'] ?? null) ? array_values($event['rosters']) : [];

        if (!in_array($statusName, self::KNOWN_WORLDCUP26_STATUS_NAMES, true)) {
            Log::warning("Unmapped worldcup26 status name: {$statusName} (fixture {$fixture->id})");
        }

        $fixture->update([
            'state' => FixtureState::fromWorldcup26Name($statusName),
            'local_score' => $this->scoreFor($competitors, 'home'),
            'guest_score' => $this->scoreFor($competitors, 'away'),
            'local_formation' => $this->formationFor($rosters, 'home'),
            'guest_formation' => $this->formationFor($rosters, 'away'),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $competitors
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
     * @param  list<array<string, mixed>>  $rosters
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
            'unresolved_name' => (string) ($rosterPlayer['athlete']['displayName'] ?? ''),
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

    /**
     * @param  array<string, mixed>  $rosterPlayer
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
     * @param  array<string, mixed>  $rosterPlayer
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

    /**
     * @param  array<string, mixed>  $event
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
                Log::warning("Unmapped worldcup26 team match_data_id {$teamMatchDataId} for fixture {$fixture->id}: dropping event");

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
     * @param  array<string, mixed>  $keyEvent
     */
    private function eventType(array $keyEvent): ?string
    {
        return match (true) {
            ($keyEvent['scoringPlay'] ?? false) === true => 'goal',
            ($keyEvent['redCard'] ?? false) === true => 'red_card',
            ($keyEvent['yellowCard'] ?? false) === true => 'yellow_card',
            ($keyEvent['penaltyKick'] ?? false) === true => 'penalty_missed',
            default => null,
        };
    }
}
```

- [ ] **Step 4: Rewrite `SyncLiveSeasonMatchData` to use the trait**

Replace `app/Console/Commands/SyncLiveSeasonMatchData.php` entirely with:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SyncsMatchData;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('season:sync-live-match-data')]
#[Description('Synchronize live/recently-finished fixtures\' state, lineups and events from worldcup26.ir')]
class SyncLiveSeasonMatchData extends Command
{
    use SyncsMatchData;

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

        $result = $this->syncMatchDataForFixtures($fixtures, $connector);

        $this->info("{$result['synced']} fixtures synced.");

        if ($result['unresolved'] !== []) {
            $message = 'Unresolved players — needs manual review: '.implode(', ', $result['unresolved']);
            $this->warn($message);
            Log::warning($message);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: PASS — both the new `unresolved_name` test and every pre-existing test in this file (the refactor changed nothing about the actual sync behavior).

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/Concerns/SyncsMatchData.php app/Console/Commands/SyncLiveSeasonMatchData.php tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php
git commit -m "refactor: extract match-data sync logic into SyncsMatchData, capture unresolved_name"
```

---

### Task 6: Fill `fantasy_points`/`fantasy_stats` from LaLiga Fantasy's live scores

**Files:**
- Modify: `app/Console/Commands/Concerns/SyncsMatchData.php`
- Modify: `app/Console/Commands/SyncLiveSeasonMatchData.php`
- Modify: `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`

**Interfaces:**
- Consumes: `LaLigaFantasyConnector::getPlayer(int $playerFantasyId)` (already exists — this is the same call the deleted `SyncsPlayerScoreStats` trait used).
- Produces: `FixtureLineup.fantasy_points`/`.fantasy_stats` filled for every RESOLVED player in the fixtures being synced (unresolved entries are skipped — there's no `fantasy_id` to look up).

This is the piece that actually replaces what the deleted `SyncLiveSeasonPlayerScores`/`SyncLiveSeasonPlayerScoreStats` commands used to do — now integrated into the same pass instead of separate commands, so match data and fantasy data update together.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php`:

```php
test('fills fantasy_points and fantasy_stats for a resolved lineup player from Fantasy live scores', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 3]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'week_number' => 3,
        'date' => now()->subMinutes(30),
    ]);
    $player = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001, 'fantasy_id' => 2759]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 5001, 'displayName' => 'Known Player'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1', 'stats' => []],
                ],
            ],
        ],
    ]);

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make([
            'id' => 2759,
            'playerStats' => [
                ['weekNumber' => 3, 'totalPoints' => 7, 'stats' => ['mins_played' => [90, 2], 'goals' => [1, 5]]],
                ['weekNumber' => 2, 'totalPoints' => 2, 'stats' => ['mins_played' => [90, 2]]],
            ],
        ]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->where('player_id', $player->id)->sole();
    expect($lineup->fantasy_points)->toBe(7)
        ->and($lineup->fantasy_stats)->toBe(['mins_played' => [90, 2], 'goals' => [1, 5]]);
});

test('leaves fantasy_points/fantasy_stats null for an unresolved lineup entry', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'week_number' => 1,
        'date' => now()->subMinutes(30),
    ]);

    $payload = liveMatchEventPayload([
        'rosters' => [
            [
                'homeAway' => 'home',
                'team' => ['id' => 83],
                'formation' => '4-3-3',
                'roster' => [
                    ['athlete' => ['id' => 9999, 'displayName' => 'Unknown Player'], 'starter' => true, 'position' => ['displayName' => 'CB'], 'jersey' => '5', 'stats' => []],
                ],
            ],
        ],
    ]);

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncLiveSeasonMatchData::class)->assertSuccessful();

    $lineup = FixtureLineup::query()->whereNull('player_id')->sole();
    expect($lineup->fantasy_points)->toBeNull()
        ->and($lineup->fantasy_stats)->toBeNull();
});
```

Add `use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;` and `use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerRequest;` to this test file's imports if not already present (check first — `GetPlayerRequest` may be named differently; run `Glob` on `app/Http/Integrations/LaLigaFantasy/Requests/` to confirm the exact class name before writing this test).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: FAIL — nothing fills `fantasy_points`/`fantasy_stats` yet.

- [ ] **Step 3: Add the fantasy-points-filling logic to the trait**

In `app/Console/Commands/Concerns/SyncsMatchData.php`, add `use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;` to the imports. Change `syncMatchDataForFixtures()`'s signature to also accept the Fantasy connector, and call a new step after `syncLineups()`:

```php
    /**
     * @param  Collection<int, Fixture>  $fixtures
     * @return array{synced: int, unresolved: list<string>}
     *
     * @throws Throwable
     */
    private function syncMatchDataForFixtures(Collection $fixtures, \App\Http\Integrations\Worldcup26\Worldcup26Connector $worldcup26Connector, LaLigaFantasyConnector $fantasyConnector): array
    {
        $synced = 0;
        $unresolved = [];

        foreach ($fixtures as $fixture) {
            try {
                $event = $worldcup26Connector->getEvent($fixture->match_data_id)->throw()->json();
            } catch (FatalRequestException|RequestException|JsonException $exception) {
                Log::warning("Failed to sync match data for fixture {$fixture->id}: {$exception->getMessage()}");

                continue;
            }

            DB::transaction(function () use ($fixture, $event, &$unresolved): void {
                $this->syncFixture($fixture, $event);
                $unresolved = [...$unresolved, ...$this->syncLineups($fixture, $event)];
                $this->syncEvents($fixture, $event);
            });

            $this->fillFantasyScores($fixture, $fantasyConnector);

            $synced++;
        }

        return ['synced' => $synced, 'unresolved' => $unresolved];
    }

    private function fillFantasyScores(Fixture $fixture, LaLigaFantasyConnector $connector): void
    {
        FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->whereNotNull('player_id')
            ->with('player')
            ->get()
            ->each(function (FixtureLineup $lineup) use ($fixture, $connector): void {
                $fantasyId = $lineup->player?->fantasy_id;

                if ($fantasyId === null) {
                    return;
                }

                try {
                    $playerData = $connector->getPlayer($fantasyId)->throw()->json();
                } catch (FatalRequestException|RequestException|JsonException $exception) {
                    Log::warning("Failed to fetch Fantasy stats for player {$fantasyId} (fixture {$fixture->id}): {$exception->getMessage()}");

                    return;
                }

                $playerStats = is_array($playerData['playerStats'] ?? null) ? $playerData['playerStats'] : [];

                $weekStats = collect($playerStats)->first(
                    fn ($stat): bool => is_array($stat) && ($stat['weekNumber'] ?? null) === $fixture->week_number,
                );

                if (!is_array($weekStats)) {
                    return;
                }

                $lineup->update([
                    'fantasy_points' => isset($weekStats['totalPoints']) ? (int) $weekStats['totalPoints'] : null,
                    'fantasy_stats' => is_array($weekStats['stats'] ?? null) ? $weekStats['stats'] : null,
                ]);
            });
    }
```

Note: this calls `getPlayer()` once per resolved player per sync run (mirrors exactly what the deleted `SyncsPlayerScoreStats` trait already did on the same schedule — not new cost, restored cost). A player with no `fantasy_id` (unresolved, or resolved but not yet linked to Fantasy) is skipped, leaving `fantasy_points`/`fantasy_stats` null — correct per the spec's accepted gap.

- [ ] **Step 4: Update `SyncLiveSeasonMatchData`'s call site**

In `app/Console/Commands/SyncLiveSeasonMatchData.php`, change `handle()`'s signature to also inject `LaLigaFantasyConnector $fantasyConnector`, and update the call: `$result = $this->syncMatchDataForFixtures($fixtures, $connector, $fantasyConnector);`. Add `use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;` to the imports.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncLiveSeasonMatchDataTest`
Expected: PASS

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/Concerns/SyncsMatchData.php app/Console/Commands/SyncLiveSeasonMatchData.php tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php
git commit -m "feat: fill fixture_lineups.fantasy_points/fantasy_stats from Fantasy live scores during match sync"
```

---

### Task 7: `SyncCurrentSeasonMatchData` — 15-minute catch-up pass

**Files:**
- Create: `app/Console/Commands/SyncCurrentSeasonMatchData.php`
- Test: `tests/Feature/Console/Commands/SyncCurrentSeasonMatchDataTest.php`

**Interfaces:**
- Produces: `season:sync-current-match-data` — same sync as `SyncLiveSeasonMatchData` (via `SyncsMatchData`), scoped to fixtures finished more than 4 hours ago (outside `SyncLiveSeasonMatchData`'s window) but within the last 48 hours.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Console\Commands\SyncCurrentSeasonMatchData;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('syncs a fixture finished 10 hours ago, outside the live window but inside 48h', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subHours(10),
    ]);

    $payload = liveMatchEventPayload();

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make($payload),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonMatchData::class)
        ->expectsOutputToContain('1 fixtures synced.')
        ->assertSuccessful();
});

test('ignores a fixture that finished more than 48 hours ago', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDays(5), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subHours(60),
    ]);

    app()->instance(Worldcup26Connector::class, (new Worldcup26Connector)->withMockClient(new MockClient([])));
    app()->instance(LaLigaFantasyConnector::class, (new LaLigaFantasyConnector)->withMockClient(new MockClient([])));

    $this->artisan(SyncCurrentSeasonMatchData::class)
        ->expectsOutputToContain('0 fixtures synced.')
        ->assertSuccessful();
});

test('ignores a fixture still inside the live window', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subHours(1),
    ]);

    app()->instance(Worldcup26Connector::class, (new Worldcup26Connector)->withMockClient(new MockClient([])));
    app()->instance(LaLigaFantasyConnector::class, (new LaLigaFantasyConnector)->withMockClient(new MockClient([])));

    $this->artisan(SyncCurrentSeasonMatchData::class)
        ->expectsOutputToContain('0 fixtures synced.')
        ->assertSuccessful();
});
```

`liveMatchEventPayload()` is defined at the top of `tests/Feature/Console/Commands/SyncLiveSeasonMatchDataTest.php` as a global test helper function — since Pest loads all test files, it's already available here without re-declaring it; if running this file in isolation fails to find it, copy the same helper function definition into this file instead (check by running the full suite, not just this file, before concluding it's missing).

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SyncCurrentSeasonMatchDataTest`
Expected: FAIL — the command doesn't exist yet.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SyncsMatchData;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('season:sync-current-match-data')]
#[Description('Catch-up sync (every ~15 min) for fixtures finished recently but outside the live sync window — late-arriving worldcup26/Fantasy corrections')]
class SyncCurrentSeasonMatchData extends Command
{
    use SyncsMatchData;

    private const int LIVE_WINDOW_HOURS = 4;

    private const int CATCH_UP_WINDOW_HOURS = 48;

    /**
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $worldcup26Connector, LaLigaFantasyConnector $fantasyConnector): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->where('date', '<', now()->subHours(self::LIVE_WINDOW_HOURS))
            ->where('date', '>=', now()->subHours(self::CATCH_UP_WINDOW_HOURS))
            ->get();

        $result = $this->syncMatchDataForFixtures($fixtures, $worldcup26Connector, $fantasyConnector);

        $this->info("{$result['synced']} fixtures synced.");

        if ($result['unresolved'] !== []) {
            $message = 'Unresolved players — needs manual review: '.implode(', ', $result['unresolved']);
            $this->warn($message);
            Log::warning($message);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncCurrentSeasonMatchDataTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncCurrentSeasonMatchData.php tests/Feature/Console/Commands/SyncCurrentSeasonMatchDataTest.php
git commit -m "feat: add SyncCurrentSeasonMatchData, a 15-minute catch-up pass outside the live sync window"
```

---

### Task 8: `SyncSeasonMatchDataBackfill` — daily full-season pass

**Files:**
- Create: `app/Console/Commands/SyncSeasonMatchDataBackfill.php`
- Test: `tests/Feature/Console/Commands/SyncSeasonMatchDataBackfillTest.php`

**Interfaces:**
- Produces: `season:sync-match-data-backfill` — same sync (via `SyncsMatchData`), scoped to every already-played, already-linked fixture in the season, regardless of age.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Console\Commands\SyncSeasonMatchDataBackfill;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('syncs a fixture from 3 weeks ago', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDays(30), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subDays(21),
    ]);

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make(liveMatchEventPayload()),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncSeasonMatchDataBackfill::class)
        ->expectsOutputToContain('1 fixtures synced.')
        ->assertSuccessful();
});

test('ignores a fixture with no match_data_id linked yet', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDays(30), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => null,
        'date' => now()->subDays(21),
    ]);

    app()->instance(Worldcup26Connector::class, (new Worldcup26Connector)->withMockClient(new MockClient([])));
    app()->instance(LaLigaFantasyConnector::class, (new LaLigaFantasyConnector)->withMockClient(new MockClient([])));

    $this->artisan(SyncSeasonMatchDataBackfill::class)
        ->expectsOutputToContain('0 fixtures synced.')
        ->assertSuccessful();
});

test('ignores a fixture from a different season', function (): void {
    $currentSeason = Season::factory()->create(['start_date' => now()->subDays(30), 'end_date' => now()->addDay()]);
    $otherSeason = Season::factory()->create(['start_date' => now()->subYears(2), 'end_date' => now()->subYear()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    Fixture::factory()->create([
        'season_id' => $otherSeason->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
        'date' => now()->subYear(),
    ]);

    app()->instance(Worldcup26Connector::class, (new Worldcup26Connector)->withMockClient(new MockClient([])));
    app()->instance(LaLigaFantasyConnector::class, (new LaLigaFantasyConnector)->withMockClient(new MockClient([])));

    $this->artisan(SyncSeasonMatchDataBackfill::class)
        ->expectsOutputToContain('0 fixtures synced.')
        ->assertSuccessful();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=SyncSeasonMatchDataBackfillTest`
Expected: FAIL — the command doesn't exist yet.

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SyncsMatchData;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('season:sync-match-data-backfill')]
#[Description('Full daily safety-net sync (every played fixture in the season) for worldcup26/Fantasy match data')]
class SyncSeasonMatchDataBackfill extends Command
{
    use SyncsMatchData;

    /**
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $worldcup26Connector, LaLigaFantasyConnector $fantasyConnector): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->where('date', '<=', now())
            ->get();

        $result = $this->syncMatchDataForFixtures($fixtures, $worldcup26Connector, $fantasyConnector);

        $this->info("{$result['synced']} fixtures synced.");

        if ($result['unresolved'] !== []) {
            $message = 'Unresolved players — needs manual review: '.implode(', ', $result['unresolved']);
            $this->warn($message);
            Log::warning($message);
        }

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=SyncSeasonMatchDataBackfillTest`
Expected: PASS

- [ ] **Step 5: Run the full test suite**

Run: `php artisan test`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/SyncSeasonMatchDataBackfill.php tests/Feature/Console/Commands/SyncSeasonMatchDataBackfillTest.php
git commit -m "feat: add SyncSeasonMatchDataBackfill, a daily full-season safety-net sync"
```

---

### Task 9: Schedule the two new commands

**Files:**
- Modify: `bootstrap/app.php`

**Interfaces:**
- Produces: `season:sync-current-match-data` scheduled every 15 minutes, `season:sync-match-data-backfill` scheduled daily at midnight.

- [ ] **Step 1: Add the two schedule entries**

In `bootstrap/app.php`, read the current `->withSchedule(function (Schedule $schedule): void { ... })` block first (it already has a `$schedule->command('season:sync-live-match-data')->everyMinute()->...` entry — add the two new entries right after it, matching the existing style):

```php
        $schedule->command('season:sync-current-match-data')
            ->everyFifteenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-match-data-backfill')
            ->dailyAt('00:00')
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();
```

- [ ] **Step 2: Verify the schedule is registered**

Run: `php artisan schedule:list`
Expected: both new commands appear alongside the existing ones, with the correct frequencies.

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test`
Expected: PASS, no regressions (scheduling doesn't affect tests, but confirms nothing else broke).

- [ ] **Step 4: Commit**

```bash
git add bootstrap/app.php
git commit -m "feat: schedule SyncCurrentSeasonMatchData (15 min) and SyncSeasonMatchDataBackfill (daily)"
```

---

### Task 10: Final verification

**Files:** none — verification only.

- [ ] **Step 1: Full backend test suite**

Run: `php artisan test`
Expected: PASS in full.

- [ ] **Step 2: Grep sweep for leftover references**

Run (via `Grep`, not shell) across `app/`, `tests/`: confirm zero remaining matches for `LinkMatchDataTeams`.

- [ ] **Step 3: Confirm the schedule is coherent**

Run: `php artisan schedule:list` — read through the full output and confirm: `season:sync-teams` runs before anything that depends on teams being linked (it already ran daily before this plan, unchanged frequency); `season:sync-current-match-data` and `season:sync-match-data-backfill` appear with the frequencies from Task 9; `season:link-match-data-teams` does NOT appear (deleted in Task 3, never was scheduled anyway).

No commit for this task — it's verification only. Report back with a summary of what was found (or confirm everything passed clean).
