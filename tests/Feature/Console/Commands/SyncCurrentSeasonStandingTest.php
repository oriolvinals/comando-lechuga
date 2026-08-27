<?php

use App\Console\Commands\SyncCurrentSeasonStanding;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueStandingRequest;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('updates an existing season manager without touching its name', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394521,
        'fantasy_user_id' => 6392099,
        'name' => 'Old name',
        'logo' => 'images/season-manager.png',
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
        ->expectsOutput('1 season managers synchronized.')
        ->assertSuccessful();

    $seasonManager->refresh();

    expect($seasonManager->name)->toBe('Old name')
        ->and($seasonManager->fantasy_user_id)->toBe(6392099)
        ->and($seasonManager->total_points)->toBe(64)
        ->and($seasonManager->live_points)->toBe(30)
        ->and($seasonManager->position)->toBe(1)
        ->and($seasonManager->last_position)->toBe(3)
        ->and($seasonManager->value)->toBe(246474249)
        ->and($seasonManager->logo)->toBe('images/managers/37394521.png');
});

test('stores a null live points when the API omits it', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueStandingRequest::class => MockResponse::make([
            [
                'position' => 1,
                'previousPosition' => 1,
                'points' => 10,
                'team' => [
                    'id' => '888888888',
                    'teamValue' => 100,
                    'manager' => [
                        'id' => 1,
                        'managerName' => 'No Live Points FC',
                        'avatar' => 'https://example.com/avatar.png',
                    ],
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonStanding::class)->assertSuccessful();

    $seasonManager = SeasonManager::query()->where('fantasy_id', 888888888)->sole();

    expect($seasonManager->live_points)->toBeNull();
});

test('leaves the logo empty when no matching image exists on disk', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueStandingRequest::class => MockResponse::make([
            [
                'position' => 1,
                'previousPosition' => 1,
                'points' => 10,
                'livePoints' => 0,
                'team' => [
                    'id' => '999999999',
                    'teamValue' => 100,
                    'manager' => [
                        'id' => 1,
                        'managerName' => 'No Logo FC',
                        'avatar' => 'https://example.com/avatar.png',
                    ],
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonStanding::class)->assertSuccessful();

    $seasonManager = SeasonManager::query()->where('fantasy_id', 999999999)->sole();

    expect($seasonManager->logo)->toBe('')
        ->and($seasonManager->name)->toBe('No Logo FC');
});
