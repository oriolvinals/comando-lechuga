<?php

use App\Console\Commands\LinkMatchDataPlayers;
use App\Enums\PlayerStatus;
use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
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
    $season->teams()->attach([$home->id, $away->id]);
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
    $season->teams()->attach([$home->id, $away->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $home->id,
        'team_guest_id' => $away->id,
        'match_data_id' => 401882926,
    ]);
    $unmatchable = Player::factory()->create([
        'team_id' => $home->id,
        'nickname' => 'Zzyzx',
        'status' => PlayerStatus::Ok,
    ]);
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
