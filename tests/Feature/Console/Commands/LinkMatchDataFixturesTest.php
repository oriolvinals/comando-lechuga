<?php

use App\Console\Commands\LinkMatchDataFixtures;
use App\Http\Integrations\Worldcup26\Requests\GetFixturesRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
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
