<?php

use App\Console\Commands\SyncCurrentLeaguePlayers;
use App\Enums\PlayerPosition;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetAssetRequest;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayersRequest;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates and updates players for the active league teams', function () {
    Storage::fake('public');

    $league = League::factory()->create(['current' => true]);
    $team = Team::factory()->create(['fantasy_id' => 3]);
    $league->teams()->attach($team);
    $existingPlayer = Player::factory()->create([
        'fantasy_id' => 68,
        'nickname' => 'Old nickname',
        'team_id' => $team->id,
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
                'id' => 69,
                'positionId' => 2,
                'nickname' => 'New player',
                'playerStatus' => 'doubtful',
                'marketValue' => '200000000',
                'points' => 12,
                'averagePoints' => 4.5,
                'image' => 'https://assets-fantasy.llt-services.com/players/t174/p69/256x256/p69.png',
                'teamId' => 3,
            ],
        ]),
        GetAssetRequest::class => MockResponse::make('player image'),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentLeaguePlayers::class)
        ->expectsOutput('2 players synchronized.')
        ->assertSuccessful();

    expect(Player::query()->count())->toBe(2)
        ->and($existingPlayer->refresh())
        ->nickname->toBe('Unai Simón')
        ->and($existingPlayer->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($existingPlayer->image)->toBe('images/player/68.png')
        ->and($existingPlayer->team_id)->toBe($team->id);

    Storage::disk('public')->assertExists([
        'images/player/68.png',
        'images/player/69.png',
    ]);
});
