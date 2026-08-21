<?php

use App\Console\Commands\SyncCurrentSeasonPlayerScores;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerRequest;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('syncs detailed scores for each player in the current season', function () {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['fantasy_id' => 2]);
    $season->teams()->attach($team);
    $player = Player::factory()->create([
        'fantasy_id' => 2534,
        'team_id' => $team->id,
    ]);
    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerRequest::class => MockResponse::make([
            'playerStats' => [
                [
                    'stats' => [
                        'goals' => [1, 5],
                        'marca_points' => [-1, 3],
                    ],
                    'weekNumber' => 1,
                    'totalPoints' => 9,
                    'isInIdealFormation' => false,
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerScores::class)
        ->expectsOutput('1 player scores synchronized.')
        ->assertSuccessful();

    $score = PlayerScore::query()->sole();

    expect($score->player_id)->toBe($player->id)
        ->and($score->week_number)->toBe(1)
        ->and($score->points)->toBe(9)
        ->and($score->stats)->toBe([
            'goals' => [1, 5],
            'marca_points' => [-1, 3],
        ])
        ->and($score->ideal_formation)->toBeFalse();
});
