<?php

use App\Console\Commands\SyncCurrentSeasonTeamLineups;
use App\Enums\PlayerPosition;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetTeamLineupRequest;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamLineupPlayer;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('syncs lineups for each season team through the current week', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $seasonTeam = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394771,
    ]);
    $player = Player::factory()->create(['fantasy_id' => 2759]);
    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');
    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetTeamLineupRequest::class => MockResponse::make([
            'formation' => [
                'goalkeeper' => [
                    [
                        'playerMaster' => [
                            'id' => '2759',
                            'points' => 6,
                        ],
                    ],
                ],
                'defender' => [],
                'midfield' => [],
                'striker' => [],
                'tacticalFormation' => [3, 5, 2],
            ],
            'points' => 6,
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeamLineups::class)
        ->expectsOutput('1 team lineups synchronized.')
        ->assertSuccessful();

    $lineup = SeasonTeamLineup::query()->sole();
    $lineupPlayer = SeasonTeamLineupPlayer::query()->sole();

    expect($lineup->season_team_id)->toBe($seasonTeam->id)
        ->and($lineup->week_number)->toBe(1)
        ->and($lineup->tactical_formation)->toBe([3, 5, 2])
        ->and($lineup->points)->toBe(6)
        ->and($lineupPlayer->player_id)->toBe($player->id)
        ->and($lineupPlayer->points)->toBe(6)
        ->and($lineupPlayer->position)->toBe(PlayerPosition::Goalkeeper);
});
