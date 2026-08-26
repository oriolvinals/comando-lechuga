<?php

use App\Console\Commands\SyncLiveSeasonPlayerScoreStats;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('only syncs players with a live fixture or one finished within the recent window', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $liveTeam = Team::factory()->create();
    $recentlyFinishedTeam = Team::factory()->create();
    $longFinishedTeam = Team::factory()->create();
    $scheduledTeam = Team::factory()->create();

    $season->teams()->attach([
        $liveTeam->id,
        $recentlyFinishedTeam->id,
        $longFinishedTeam->id,
        $scheduledTeam->id,
    ]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $liveTeam->id,
        'state' => FixtureState::SecondHalf,
        'date' => now()->subMinutes(60),
    ]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $recentlyFinishedTeam->id,
        'state' => FixtureState::Finished,
        'date' => now()->subHours(1),
    ]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $longFinishedTeam->id,
        'state' => FixtureState::Finished,
        'date' => now()->subHours(6),
    ]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $scheduledTeam->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addHours(2),
    ]);

    $livePlayer = Player::factory()->create(['fantasy_id' => 1, 'team_id' => $liveTeam->id, 'points' => 0, 'status' => PlayerStatus::Ok]);
    $recentPlayer = Player::factory()->create(['fantasy_id' => 2, 'team_id' => $recentlyFinishedTeam->id, 'points' => 0, 'status' => PlayerStatus::Ok]);
    $oldPlayer = Player::factory()->create(['fantasy_id' => 3, 'team_id' => $longFinishedTeam->id, 'points' => 0, 'status' => PlayerStatus::Ok]);
    $scheduledPlayer = Player::factory()->create(['fantasy_id' => 4, 'team_id' => $scheduledTeam->id, 'points' => 0, 'status' => PlayerStatus::Ok]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        MockResponse::make(['points' => 10, 'playerStats' => []]),
        MockResponse::make(['points' => 20, 'playerStats' => []]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncLiveSeasonPlayerScoreStats::class)
        ->expectsOutput('0 player scores updated with stats.')
        ->assertSuccessful();

    expect($livePlayer->refresh()->points)->toBe(10)
        ->and($recentPlayer->refresh()->points)->toBe(20)
        ->and($oldPlayer->refresh()->points)->toBe(0)
        ->and($scheduledPlayer->refresh()->points)->toBe(0);
});

test('excludes players with out-of-league status even when their fixture is live', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $liveTeam = Team::factory()->create();
    $season->teams()->attach($liveTeam->id);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $liveTeam->id,
        'state' => FixtureState::SecondHalf,
        'date' => now()->subMinutes(60),
    ]);

    $activePlayer = Player::factory()->create(['fantasy_id' => 1, 'team_id' => $liveTeam->id, 'points' => 0, 'status' => PlayerStatus::Ok]);
    $outOfLeaguePlayer = Player::factory()->create(['fantasy_id' => 2, 'team_id' => $liveTeam->id, 'points' => 0, 'status' => PlayerStatus::OutOfLeague]);

    // Only one response is queued: if the out-of-league player were also fetched, this would run out and fail.
    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        MockResponse::make(['points' => 10, 'playerStats' => []]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncLiveSeasonPlayerScoreStats::class)
        ->expectsOutput('0 player scores updated with stats.')
        ->assertSuccessful();

    expect($activePlayer->refresh()->points)->toBe(10)
        ->and($outOfLeaguePlayer->refresh()->points)->toBe(0);
});
