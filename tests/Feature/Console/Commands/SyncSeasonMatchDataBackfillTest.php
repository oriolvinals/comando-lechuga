<?php

use App\Console\Commands\SyncSeasonMatchDataBackfill;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerRequest;
use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
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

test('fetches a player Fantasy stats only once per run even when they appear in multiple fixtures', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDays(30), 'end_date' => now()->addDay()]);
    $home = Team::factory()->create(['match_data_id' => 83]);
    $away = Team::factory()->create(['match_data_id' => 86]);
    $season->teams()->attach([$home->id, $away->id]);
    $firstFixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 111,
        'week_number' => 1,
        'date' => now()->subDays(21),
    ]);
    $secondFixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 222,
        'week_number' => 2,
        'date' => now()->subDays(14),
    ]);
    $player = Player::factory()->create(['team_id' => $home->id, 'match_data_id' => 5001, 'fantasy_id' => 7777]);

    $roster = [
        [
            'homeAway' => 'home',
            'team' => ['id' => 83],
            'formation' => '4-3-3',
            'roster' => [
                ['athlete' => ['id' => 5001, 'displayName' => 'Repeat Player'], 'starter' => true, 'position' => ['displayName' => 'GK'], 'jersey' => '1', 'stats' => []],
            ],
        ],
    ];

    // Same roster payload for both fixtures — the player appears (resolved) in both.
    $worldcup26Connector = (new Worldcup26Connector)->withMockClient(new MockClient([
        GetEventRequest::class => MockResponse::make(liveMatchEventPayload(['rosters' => $roster])),
    ]));
    app()->instance(Worldcup26Connector::class, $worldcup26Connector);

    $fantasyMockClient = new MockClient([
        GetPlayerRequest::class => MockResponse::make([
            'id' => 7777,
            'playerStats' => [
                ['weekNumber' => 1, 'totalPoints' => 5, 'stats' => ['mins_played' => [90, 2]]],
                ['weekNumber' => 2, 'totalPoints' => 9, 'stats' => ['mins_played' => [90, 2], 'goals' => [1, 5]]],
            ],
        ]),
    ]);
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient($fantasyMockClient);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncSeasonMatchDataBackfill::class)
        ->expectsOutputToContain('2 fixtures synced.')
        ->assertSuccessful();

    $fantasyMockClient->assertSentCount(1, GetPlayerRequest::class);

    $firstLineup = FixtureLineup::query()->where('fixture_id', $firstFixture->id)->where('player_id', $player->id)->sole();
    $secondLineup = FixtureLineup::query()->where('fixture_id', $secondFixture->id)->where('player_id', $player->id)->sole();

    expect($firstLineup->fantasy_points)->toBe(5)
        ->and($firstLineup->fantasy_stats)->toBe(['mins_played' => [90, 2]])
        ->and($secondLineup->fantasy_points)->toBe(9)
        ->and($secondLineup->fantasy_stats)->toBe(['mins_played' => [90, 2], 'goals' => [1, 5]]);
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
