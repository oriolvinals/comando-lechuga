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
