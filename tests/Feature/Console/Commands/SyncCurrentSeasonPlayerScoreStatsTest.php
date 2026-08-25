<?php

use App\Console\Commands\SyncCurrentSeasonPlayerScoreStats;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerRequest;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('updates the stats and ideal formation flag for an existing player score', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $player = Player::factory()->create(['fantasy_id' => 2534, 'team_id' => $team->id, 'points' => 40]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    $score = PlayerScore::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'points' => 3,
        'stats' => [],
        'ideal_formation' => false,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make([
            'points' => 47,
            'playerStats' => [
                [
                    'stats' => [
                        'goals' => [1, 5],
                        'marca_points' => [-1, 3],
                    ],
                    'weekNumber' => 1,
                    'totalPoints' => 9,
                    'isInIdealFormation' => true,
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerScoreStats::class)
        ->expectsOutput('1 player scores updated with stats.')
        ->assertSuccessful();

    $score->refresh();

    expect($score->stats)->toBe([
        'goals' => [1, 5],
        'marca_points' => [-1, 3],
    ])
        ->and($score->ideal_formation)->toBeTrue()
        ->and($score->points)->toBe(9)
        ->and($player->refresh()->points)->toBe(47);
});

test('leaves existing points untouched when totalPoints is missing', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $player = Player::factory()->create(['fantasy_id' => 2534, 'team_id' => $team->id]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    $score = PlayerScore::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'points' => 5,
        'stats' => [],
        'ideal_formation' => false,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make([
            'playerStats' => [
                [
                    'stats' => ['goals' => [1, 5]],
                    'weekNumber' => 1,
                    'isInIdealFormation' => true,
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerScoreStats::class)
        ->expectsOutput('1 player scores updated with stats.')
        ->assertSuccessful();

    expect($score->refresh()->points)->toBe(5);
});

test('does not create a score when none exists for that fixture', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    Player::factory()->create(['fantasy_id' => 2534, 'team_id' => $team->id]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make([
            'playerStats' => [
                [
                    'stats' => ['goals' => [1, 5]],
                    'weekNumber' => 1,
                    'totalPoints' => 9,
                    'isInIdealFormation' => false,
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerScoreStats::class)
        ->expectsOutput('0 player scores updated with stats.')
        ->assertSuccessful();

    expect(PlayerScore::query()->count())->toBe(0);
});
