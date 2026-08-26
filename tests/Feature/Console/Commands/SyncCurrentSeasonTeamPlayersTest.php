<?php

use App\Console\Commands\SyncCurrentSeasonTeamPlayers;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueTeamRequest;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamPlayer;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates the current squad for each season team and skips unresolved players', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394521,
    ]);
    $player = Player::factory()->create(['fantasy_id' => 988]);

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueTeamRequest::class => MockResponse::make([
            'players' => [
                [
                    'buyoutClause' => 35273936,
                    'buyoutClauseLockedEndTime' => '2026-08-25T20:00:49+02:00',
                    'isShielded' => false,
                    'playerMaster' => ['id' => '988'],
                ],
                [
                    'buyoutClause' => 1000000,
                    'buyoutClauseLockedEndTime' => '2026-08-25T20:00:49+02:00',
                    'isShielded' => false,
                    'playerMaster' => ['id' => '999999'],
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeamPlayers::class)
        ->expectsOutput('1 season team squads synchronized.')
        ->assertSuccessful();

    $seasonTeamPlayer = SeasonTeamPlayer::query()->sole();

    expect($seasonTeamPlayer->season_team_id)->toBe($seasonTeam->id)
        ->and($seasonTeamPlayer->player_id)->toBe($player->id)
        ->and($seasonTeamPlayer->buyout_clause)->toBe(35273936)
        ->and($seasonTeamPlayer->shielded)->toBeFalse()
        ->and($seasonTeamPlayer->shielded_until)->toBeNull()
        ->and($seasonTeamPlayer->buyout_clause_locked_until->toIso8601String())
        ->toBe('2026-08-25T20:00:49+02:00');
});

test('stores the shield expiry date for a shielded player', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394521,
    ]);
    Player::factory()->create(['fantasy_id' => 988]);

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueTeamRequest::class => MockResponse::make([
            'players' => [
                [
                    'buyoutClause' => 35273936,
                    'buyoutClauseLockedEndTime' => '2026-08-14T20:10:21+02:00',
                    'isShielded' => true,
                    'shieldedEndDate' => '2026-08-27T21:03:25+02:00',
                    'playerMaster' => ['id' => '988'],
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeamPlayers::class)->assertSuccessful();

    $seasonTeamPlayer = SeasonTeamPlayer::query()->sole();

    expect($seasonTeamPlayer->shielded)->toBeTrue()
        ->and($seasonTeamPlayer->shielded_until?->toIso8601String())
        ->toBe('2026-08-27T21:03:25+02:00')
        ->and($seasonTeamPlayer->buyout_clause_locked_until->toIso8601String())
        ->toBe('2026-08-14T20:10:21+02:00');
});

test('removes players that are no longer part of the current squad', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394521,
    ]);
    $remainingPlayer = Player::factory()->create(['fantasy_id' => 988]);
    $soldPlayer = Player::factory()->create(['fantasy_id' => 3040]);
    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $soldPlayer->id,
    ]);

    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueTeamRequest::class => MockResponse::make([
            'players' => [
                [
                    'buyoutClause' => 35273936,
                    'buyoutClauseLockedEndTime' => '2026-08-25T20:00:49+02:00',
                    'isShielded' => false,
                    'playerMaster' => ['id' => '988'],
                ],
            ],
        ]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);

    $this->artisan(SyncCurrentSeasonTeamPlayers::class)->assertSuccessful();

    expect(SeasonTeamPlayer::query()->count())->toBe(1)
        ->and(SeasonTeamPlayer::query()->where('player_id', $remainingPlayer->id)->exists())->toBeTrue()
        ->and(SeasonTeamPlayer::query()->where('player_id', $soldPlayer->id)->exists())->toBeFalse();
});
