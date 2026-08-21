<?php

use App\Console\Commands\SyncSeasonMarket;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueMarketRequest;
use App\Models\MarketPlayer;
use App\Models\Player;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('syncs league market players and removes expired listings', function () {
    Cache::forget('la_liga_fantasy.access_token');

    $player = Player::factory()->create(['fantasy_id' => 3105]);
    MarketPlayer::factory()->create([
        'fantasy_id' => 1,
        'player_id' => $player->id,
        'expires_at' => now()->addHour(),
    ]);

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueMarketRequest::class => MockResponse::make([
            [
                'discr' => 'marketPlayerTeam',
                'id' => '2',
            ],
            [
                'discr' => 'marketPlayerLeague',
                'id' => '75224757',
                'expirationDate' => '2026-08-21T20:00:00+02:00',
                'bids' => 0,
                'numberOfBids' => 1,
                'salePrice' => 4082439,
                'playerMaster' => [
                    'id' => '3105',
                    'marketValue' => 4101822,
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncSeasonMarket::class)
        ->expectsOutput('1 market players synchronized.')
        ->assertSuccessful();

    $marketPlayer = MarketPlayer::query()->sole();

    expect($marketPlayer->fantasy_id)->toBe(75224757)
        ->and($marketPlayer->player_id)->toBe($player->id)
        ->and($marketPlayer->bids)->toBe(1)
        ->and($marketPlayer->sale_price)->toBe(4082439)
        ->and($marketPlayer->value)->toBe(4101822);
});
