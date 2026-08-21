<?php

use App\Console\Commands\SyncCurrentSeasonStanding;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueStandingRequest;
use App\Models\Season;
use App\Models\SeasonTeam;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates or updates season teams from the private standing', function () {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394521,
        'fantasy_user_id' => 6392099,
        'name' => 'Old name',
        'logo' => 'images/season-team.png',
    ]);
    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueStandingRequest::class => MockResponse::make([
            [
                'position' => 1,
                'previousPosition' => 3,
                'points' => 64,
                'livePoints' => 30,
                'team' => [
                    'id' => '37394521',
                    'teamValue' => 246474249,
                    'manager' => [
                        'id' => 6392099,
                        'managerName' => 'Gauchitos F.C',
                        'avatar' => 'https://example.com/avatar.png',
                    ],
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonStanding::class)
        ->expectsOutput('1 season teams synchronized.')
        ->assertSuccessful();

    $seasonTeam->refresh();

    expect($seasonTeam->name)->toBe('Gauchitos F.C')
        ->and($seasonTeam->fantasy_user_id)->toBe(6392099)
        ->and($seasonTeam->total_points)->toBe(64)
        ->and($seasonTeam->live_points)->toBe(30)
        ->and($seasonTeam->position)->toBe(1)
        ->and($seasonTeam->last_position)->toBe(3)
        ->and($seasonTeam->value)->toBe(246474249)
        ->and($seasonTeam->logo)->toBe('images/season-team.png');
});
