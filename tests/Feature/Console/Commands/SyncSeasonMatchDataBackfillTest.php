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
