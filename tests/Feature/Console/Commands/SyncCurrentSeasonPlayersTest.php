<?php

use App\Console\Commands\SyncCurrentSeasonPlayers;
use App\Enums\PlayerPosition;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayersRequest;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates and updates players for the active season teams', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['fantasy_id' => 3]);
    $season->teams()->attach($team);
    $existingPlayer = Player::factory()->create([
        'fantasy_id' => 68,
        'nickname' => 'Old nickname',
        'image' => 'images/player/68.png',
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
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayers::class)
        ->expectsOutput('2 players synchronized.')
        ->assertSuccessful();

    expect(Player::query()->count())->toBe(2)
        ->and($existingPlayer->refresh())
        ->nickname->toBe('Unai Simón')
        ->and($existingPlayer->image)->toBe('images/player/68.png')
        ->and($existingPlayer->team_id)->toBe($team->id)
        ->and($existingPlayer->seasons()->where('season_id', $season->id)->sole()->position)->toBe(PlayerPosition::Goalkeeper);
});
