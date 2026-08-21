<?php

use App\Console\Commands\SyncCurrentSeasonPlayerMarkets;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerMarketValueRequest;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('updates player market history and caches the latest difference', function () {
    $season = Season::factory()->create(['current' => true]);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $player = Player::factory()->create([
        'fantasy_id' => 2783,
        'team_id' => $team->id,
    ]);
    PlayerMarket::factory()->create([
        'fantasy_id' => 4002783,
        'player_id' => $player->id,
        'date' => '2026-08-20',
        'value' => 100,
    ]);

    $connector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetPlayerMarketValueRequest::class => MockResponse::make([
            [
                'lfpId' => 4002783,
                'marketValue' => 120,
                'date' => '2026-08-20T00:00:00+02:00',
            ],
            [
                'lfpId' => 4002783,
                'marketValue' => 150,
                'date' => '2026-08-21T00:00:00+02:00',
            ],
        ]),
    ]));

    app()->instance(LaLigaFantasyConnector::class, $connector);

    $this->artisan(SyncCurrentSeasonPlayerMarkets::class)
        ->expectsOutput('1 player markets synchronized.')
        ->assertSuccessful();

    expect(PlayerMarket::query()->where('player_id', $player->id)->count())->toBe(2)
        ->and($player->refresh()->market_value_difference)->toBe(30)
        ->and(PlayerMarket::query()->where('date', '2026-08-20')->sole()->value)->toBe(120);
});
