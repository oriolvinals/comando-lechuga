<?php

use App\Console\Commands\SyncLiveSeasonPlayerScores;
use App\Enums\FixtureState;
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

test('only fetches weeks with a live fixture or one finished within the recent window', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);

    $oldTeam = Team::factory()->create(['fantasy_id' => 1]);
    $liveTeam = Team::factory()->create(['fantasy_id' => 14]);
    $season->teams()->attach([$oldTeam->id, $liveTeam->id]);

    Fixture::factory()->create([
        'fantasy_id' => 11,
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $oldTeam->id,
        'state' => FixtureState::Finished,
        'date' => now()->subHours(6),
    ]);
    Player::factory()->create(['fantasy_id' => 886, 'team_id' => $oldTeam->id, 'status' => PlayerStatus::Ok]);

    Fixture::factory()->create([
        'fantasy_id' => 12,
        'season_id' => $season->id,
        'week_number' => 2,
        'team_local_id' => $liveTeam->id,
        'state' => FixtureState::SecondHalf,
        'date' => now()->subMinutes(60),
    ]);
    Player::factory()->create(['fantasy_id' => 2577, 'team_id' => $liveTeam->id, 'status' => PlayerStatus::Ok]);

    // Points a matching fixture id of 12 (week 2) so a call for week 1 would also match it,
    // making a leaked call to week 1 show up as a second synchronized score.
    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetWeekStatsRequest::class => MockResponse::make([
            [
                'id' => 12,
                'local' => [
                    'id' => 14,
                    'players' => [
                        ['id' => 2577, 'teamId' => 14, 'weekPoints' => 9],
                    ],
                ],
                'visitor' => ['id' => 21, 'players' => []],
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncLiveSeasonPlayerScores::class)
        ->expectsOutput('1 player scores synchronized.')
        ->assertSuccessful();

    expect(PlayerScore::query()->count())->toBe(1);
});

test('synchronizes no weeks when nothing is live or recently finished', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);

    $team = Team::factory()->create();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addHours(2),
    ]);

    app()->instance(LaLigaFantasyConnector::class, new LaLigaFantasyConnector);

    $this->artisan(SyncLiveSeasonPlayerScores::class)
        ->expectsOutput('0 player scores synchronized.')
        ->assertSuccessful();
});
