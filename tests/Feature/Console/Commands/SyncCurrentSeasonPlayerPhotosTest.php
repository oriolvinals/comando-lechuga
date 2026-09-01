<?php

use App\Console\Commands\SyncCurrentSeasonPlayerPhotos;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetAssetRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayersRequest;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('downloads and stores photos for players on the active season teams', function (): void {
    Storage::fake('public');

    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['fantasy_id' => 3]);
    $season->teams()->attach($team);
    $existingPlayer = Player::factory()->create([
        'fantasy_id' => 68,
        'nickname' => 'Unai Simón',
        'image' => '',
        'team_id' => $team->id,
    ]);
    $otherTeamPlayer = Player::factory()->create([
        'fantasy_id' => 99,
        'image' => '',
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayersRequest::class => MockResponse::make([
            [
                'id' => 68,
                'positionId' => 1,
                'nickname' => 'Unai Simón',
                'playerStatus' => 'ok',
                'marketValue' => '50905535',
                'points' => 0,
                'averagePoints' => 0,
                'image' => 'https://assets-fantasy.llt-services.com/players/t174/p68/256x256/p68.png',
                'teamId' => 3,
            ],
            [
                'id' => 99,
                'positionId' => 2,
                'nickname' => 'Not rostered',
                'playerStatus' => 'ok',
                'marketValue' => '1000000',
                'points' => 0,
                'averagePoints' => 0,
                'image' => 'https://assets-fantasy.llt-services.com/players/t174/p99/256x256/p99.png',
                'teamId' => 999,
            ],
        ]),
        GetAssetRequest::class => MockResponse::make('player image'),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerPhotos::class)
        ->expectsOutput('1 player photos synchronized.')
        ->assertSuccessful();

    expect($existingPlayer->refresh()->image)->toBe('images/player/68.png')
        ->and($otherTeamPlayer->refresh()->image)->toBe('');

    Storage::disk('public')->assertExists('images/player/68.png');
    Storage::disk('public')->assertMissing('images/player/99.png');
});

test('downloads a photo whose URL contains an unencoded space', function (): void {
    Storage::fake('public');

    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['fantasy_id' => 3]);
    $season->teams()->attach($team);
    $player = Player::factory()->create([
        'fantasy_id' => 68,
        'image' => '',
        'team_id' => $team->id,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayersRequest::class => MockResponse::make([
            [
                'id' => 68,
                'positionId' => 1,
                'nickname' => 'Unai Simón',
                'playerStatus' => 'ok',
                'marketValue' => '1',
                'points' => 0,
                'averagePoints' => 0,
                'image' => 'https://assets-fantasy.llt-services.com/players/t174/p68/256x256/p68_t174_1_001_000 (1).png',
                'teamId' => 3,
            ],
        ]),
        GetAssetRequest::class => MockResponse::make('player image'),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerPhotos::class)
        ->expectsOutput('1 player photos synchronized.')
        ->assertSuccessful();

    expect($player->refresh()->image)->toBe('images/player/68.png');
    Storage::disk('public')->assertExists('images/player/68.png');

    $connector->getMockClient()->assertSent(
        fn ($request, $response): bool => (string)$response->getPendingRequest()->getUri()
            === 'https://assets-fantasy.llt-services.com/players/t174/p68/256x256/p68_t174_1_001_000%20%281%29.png',
    );
});

test('skips a player whose photo URL 404s and keeps syncing the rest', function (): void {
    Storage::fake('public');

    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['fantasy_id' => 3]);
    $season->teams()->attach($team);
    $missingPhoto = Player::factory()->create([
        'fantasy_id' => 68,
        'image' => '',
        'team_id' => $team->id,
    ]);
    $hasPhoto = Player::factory()->create([
        'fantasy_id' => 70,
        'image' => '',
        'team_id' => $team->id,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayersRequest::class => MockResponse::make([
            [
                'id' => 68,
                'positionId' => 1,
                'nickname' => '404 Player',
                'playerStatus' => 'ok',
                'marketValue' => '1',
                'points' => 0,
                'averagePoints' => 0,
                'image' => 'https://assets-fantasy.llt-services.com/players/t174/p68/256x256/p68.png',
                'teamId' => 3,
            ],
            [
                'id' => 70,
                'positionId' => 1,
                'nickname' => 'Has Photo',
                'playerStatus' => 'ok',
                'marketValue' => '1',
                'points' => 0,
                'averagePoints' => 0,
                'image' => 'https://assets-fantasy.llt-services.com/players/t174/p70/256x256/p70.png',
                'teamId' => 3,
            ],
        ]),
        '*p68*' => MockResponse::make('not found', 404),
        '*p70*' => MockResponse::make('player image'),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerPhotos::class)
        ->expectsOutput('1 player photos synchronized.')
        ->assertSuccessful();

    expect($missingPhoto->refresh()->image)->toBe('')
        ->and($hasPhoto->refresh()->image)->toBe('images/player/70.png');

    Storage::disk('public')->assertMissing('images/player/68.png');
    Storage::disk('public')->assertExists('images/player/70.png');
});
