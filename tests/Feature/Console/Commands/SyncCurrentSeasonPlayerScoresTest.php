<?php

use App\Console\Commands\SyncCurrentSeasonPlayerScores;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetWeekStatsRequest;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates player scores with the fixture and team from the week stats', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);

    $localTeam = Team::factory()->create(['fantasy_id' => 14]);
    $visitorTeam = Team::factory()->create(['fantasy_id' => 21]);
    $season->teams()->attach([$localTeam->id, $visitorTeam->id]);

    $fixture = Fixture::factory()->create([
        'fantasy_id' => 12,
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $localTeam->id,
        'team_guest_id' => $visitorTeam->id,
    ]);

    $localPlayer = Player::factory()->create(['fantasy_id' => 886, 'team_id' => $localTeam->id, 'status' => PlayerStatus::Ok]);
    $visitorPlayer = Player::factory()->create(['fantasy_id' => 2577, 'team_id' => $visitorTeam->id, 'status' => PlayerStatus::Ok]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetWeekStatsRequest::class => MockResponse::make([
            [
                'id' => 12,
                'local' => [
                    'id' => 14,
                    'players' => [
                        ['id' => 886, 'teamId' => 14, 'weekPoints' => 9],
                    ],
                ],
                'visitor' => [
                    'id' => 21,
                    'players' => [
                        ['id' => 2577, 'teamId' => 21, 'weekPoints' => 7],
                    ],
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerScores::class)
        ->expectsOutput('2 player scores synchronized.')
        ->assertSuccessful();

    $localScore = PlayerScore::query()->where('player_id', $localPlayer->id)->sole();
    $visitorScore = PlayerScore::query()->where('player_id', $visitorPlayer->id)->sole();

    expect($localScore->fixture_id)->toBe($fixture->id)
        ->and($localScore->team_id)->toBe($localTeam->id)
        ->and($localScore->points)->toBe(9)
        ->and($visitorScore->fixture_id)->toBe($fixture->id)
        ->and($visitorScore->team_id)->toBe($visitorTeam->id)
        ->and($visitorScore->points)->toBe(7);
});

test('updates the points and team without touching existing stats', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);

    $team = Team::factory()->create(['fantasy_id' => 14]);
    $season->teams()->attach($team->id);
    $fixture = Fixture::factory()->create([
        'fantasy_id' => 12,
        'season_id' => $season->id,
        'week_number' => 1,
    ]);
    $player = Player::factory()->create(['fantasy_id' => 886, 'team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    $score = PlayerScore::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'points' => 3,
        'stats' => ['goals' => [1, 5]],
        'ideal_formation' => true,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetWeekStatsRequest::class => MockResponse::make([
            [
                'id' => 12,
                'local' => [
                    'id' => 14,
                    'players' => [
                        ['id' => 886, 'teamId' => 14, 'weekPoints' => 9],
                    ],
                ],
                'visitor' => ['id' => 21, 'players' => []],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerScores::class)->assertSuccessful();

    $score->refresh();

    expect($score->points)->toBe(9)
        ->and($score->team_id)->toBe($team->id)
        ->and($score->stats)->toBe(['goals' => [1, 5]])
        ->and($score->ideal_formation)->toBeTrue();
});

test('does not create a score for a player with out-of-league status', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);

    $team = Team::factory()->create(['fantasy_id' => 14]);
    $season->teams()->attach($team->id);
    Fixture::factory()->create([
        'fantasy_id' => 12,
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
    ]);
    Player::factory()->create(['fantasy_id' => 886, 'team_id' => $team->id, 'status' => PlayerStatus::OutOfLeague]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetWeekStatsRequest::class => MockResponse::make([
            [
                'id' => 12,
                'local' => [
                    'id' => 14,
                    'players' => [
                        ['id' => 886, 'teamId' => 14, 'weekPoints' => 9],
                    ],
                ],
                'visitor' => ['id' => 21, 'players' => []],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerScores::class)
        ->expectsOutput('0 player scores synchronized.')
        ->assertSuccessful();

    expect(PlayerScore::query()->count())->toBe(0);
});
