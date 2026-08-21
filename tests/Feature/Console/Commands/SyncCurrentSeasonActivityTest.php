<?php

use App\Console\Commands\SyncCurrentSeasonActivity;
use App\Enums\SeasonActivityType;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates season activities from the paginated activity feed', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $buyer = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_user_id' => 11757415,
    ]);
    $seller = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_user_id' => 2035022,
    ]);
    $player = Player::factory()->create(['fantasy_id' => 2329]);

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        MockResponse::make([
            [
                'activityTypeId' => 1,
                'id' => '20544177',
                'user1Id' => 11757415,
                'user2Id' => 2035022,
                'playerMasterId' => 2329,
                'amount' => 11268629,
                'createdAt' => '2026-08-21T14:23:09+02:00',
            ],
            [
                'activityTypeId' => 6,
                'id' => '18324270',
                'user1Id' => 11757415,
                'amount' => 2400000,
                'weekNumber' => 1,
                'createdAt' => '2026-08-20T04:23:31+02:00',
            ],
            [
                'activityTypeId' => 999,
                'id' => '99999999',
                'user1Id' => 11757415,
                'createdAt' => '2026-08-20T04:23:31+02:00',
            ],
            [
                'activityTypeId' => 31,
                'id' => '11111111',
                'user1Id' => 999999999,
                'playerMasterId' => 2329,
                'amount' => 100,
                'createdAt' => '2026-08-20T04:23:31+02:00',
            ],
        ]),
        MockResponse::make([]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonActivity::class)
        ->expectsOutput('2 season activities synchronized.')
        ->assertSuccessful();

    expect(SeasonActivity::query()->count())->toBe(2);

    $buyout = SeasonActivity::query()->where('fantasy_id', 20544177)->sole();

    expect($buyout->type)->toBe(SeasonActivityType::Buyout)
        ->and($buyout->source_season_team_id)->toBe($buyer->id)
        ->and($buyout->target_season_team_id)->toBe($seller->id)
        ->and($buyout->player_id)->toBe($player->id)
        ->and($buyout->amount)->toBe(11268629)
        ->and($buyout->week_number)->toBeNull();

    $weeklyPrize = SeasonActivity::query()->where('fantasy_id', 18324270)->sole();

    expect($weeklyPrize->type)->toBe(SeasonActivityType::WeeklyPrize)
        ->and($weeklyPrize->source_season_team_id)->toBe($buyer->id)
        ->and($weeklyPrize->target_season_team_id)->toBeNull()
        ->and($weeklyPrize->player_id)->toBeNull()
        ->and($weeklyPrize->amount)->toBe(2400000)
        ->and($weeklyPrize->week_number)->toBe(1);
});

test('does not duplicate season activities when synchronized twice', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_user_id' => 11757415,
    ]);

    $activityPage = [
        [
            'activityTypeId' => 4,
            'id' => '19720928',
            'user1Id' => 11757415,
            'playerMasterId' => 2634,
            'createdAt' => '2026-08-20T20:48:18+02:00',
        ],
    ];

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');

    app()->instance(LaLigaLoginConnector::class, $loginConnector);

    foreach (range(1, 2) as $_) {
        app()->instance(LaLigaFantasyConnector::class, (new LaLigaFantasyConnector)->withMockClient(new MockClient([
            MockResponse::make($activityPage),
            MockResponse::make([]),
        ])));

        $this->artisan(SyncCurrentSeasonActivity::class)->assertSuccessful();
    }

    expect(SeasonActivity::query()->count())->toBe(1);
});
