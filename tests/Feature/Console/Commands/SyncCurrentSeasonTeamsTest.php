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
    $season = Season::factory()->create([
        'match_data_season_slug' => '2026-27-laliga',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

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
    Season::factory()->create([
        'match_data_season_slug' => '2026-27-laliga',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

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
    $season = Season::factory()->create([
        'match_data_season_slug' => '2026-27-laliga',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
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

    // 2, not 1: the fixture event's two competitors (Real Madrid + Villarreal) both get
    // created/updated by syncFromWorldcup26() — that's the worldcup26-first design. What this
    // test actually verifies is that enrichFromFantasy() never creates its own row for
    // fantasy_id 4: it must find and update the team syncFromWorldcup26() already produced,
    // not add a duplicate.
    expect(Team::query()->count())->toBe(2)
        ->and(Team::query()->where('fantasy_id', 4)->count())->toBe(1)
        ->and($existing->fresh()->main_name)->toBe('Real Madrid CF')
        ->and($existing->fresh()->slug)->toBe('real-madrid');
});

test('skips a team with no TEAM_MAP entry instead of crashing, and still syncs the mapped team', function (): void {
    $season = Season::factory()->create([
        'match_data_season_slug' => '2026-27-laliga',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    // 999999 is not a value in TEAM_MAP — this simulates a promoted/relegated club
    // not yet added to the hardcoded map.
    $event = worldcup26FixtureEvent(83, 'Real Madrid', 'RMA', 999999, 'Unmapped Team', 'UNM');

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetFixturesRequest::class => MockResponse::make(worldcup26FixturesPayload([$event])),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamInfoRequest::class => MockResponse::make([]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeams::class)
        ->expectsOutputToContain('Unmapped Team')
        ->assertSuccessful();

    expect(Team::query()->where('match_data_id', 999999)->exists())->toBeFalse();

    $realMadrid = Team::query()->where('match_data_id', 83)->sole();
    expect($realMadrid->name)->toBe('Real Madrid')
        ->and($realMadrid->fantasy_id)->toBe(4);
});

test('leaves the season_team pivot untouched when nothing matches the current season slug', function (): void {
    $season = Season::factory()->create([
        'match_data_season_slug' => '2026-27-laliga',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $existingTeam = Team::factory()->create(['match_data_id' => 83, 'fantasy_id' => 4]);
    $season->teams()->attach([$existingTeam->id]);

    $otherSeasonEvent = worldcup26FixtureEvent(83, 'Real Madrid', 'RMA', 86, 'Villarreal', 'VIL');
    $otherSeasonEvent['season']['slug'] = '2025-26-laliga';

    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetFixturesRequest::class => MockResponse::make(worldcup26FixturesPayload([$otherSeasonEvent])),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamInfoRequest::class => MockResponse::make([]),
    ]));
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeams::class)->assertSuccessful();

    expect(Season::current()->teams->pluck('id')->all())->toEqualCanonicalizing([$existingTeam->id]);
});

test('syncs the season_team pivot so downstream commands can find the current season teams', function (): void {
    $season = Season::factory()->create([
        'match_data_season_slug' => '2026-27-laliga',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

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

    $realMadridId = Team::query()->where('match_data_id', 83)->sole()->id;
    $villarrealId = Team::query()->where('match_data_id', 86)->sole()->id;

    expect(Season::current()->teams->pluck('id')->all())
        ->toEqualCanonicalizing([$realMadridId, $villarrealId]);
});
