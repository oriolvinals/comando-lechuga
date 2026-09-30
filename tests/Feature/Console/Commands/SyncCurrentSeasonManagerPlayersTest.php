<?php

use App\Console\Commands\SyncCurrentSeasonManagerPlayers;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueTeamRequest;
use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

test('creates the current squad for each season manager and skips unresolved players', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create([
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

    $this->artisan(SyncCurrentSeasonManagerPlayers::class)
        ->expectsOutput('1 season manager squads synchronized.')
        ->assertSuccessful();

    $seasonManagerPlayer = ManagerPlayer::query()->sole();

    expect($seasonManagerPlayer->season_manager_id)->toBe($seasonManager->id)
        ->and($seasonManagerPlayer->player_id)->toBe($player->id)
        ->and($seasonManagerPlayer->buyout_clause)->toBe(35273936)
        ->and($seasonManagerPlayer->shielded)->toBeFalse()
        ->and($seasonManagerPlayer->shielded_until)->toBeNull()
        ->and($seasonManagerPlayer->buyout_clause_locked_until->toIso8601String())
        ->toBe('2026-08-25T20:00:49+02:00');
});

test('stores the shield expiry date for a shielded player', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create([
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

    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();

    $seasonManagerPlayer = ManagerPlayer::query()->sole();

    expect($seasonManagerPlayer->shielded)->toBeTrue()
        ->and($seasonManagerPlayer->shielded_until?->toIso8601String())
        ->toBe('2026-08-27T21:03:25+02:00')
        ->and($seasonManagerPlayer->buyout_clause_locked_until->toIso8601String())
        ->toBe('2026-08-14T20:10:21+02:00');
});

test('removes players that are no longer part of the current squad', function (): void {
    Cache::forget('la_liga_fantasy.access_token');

    $season = Season::factory()->create([
        'fantasy_id' => '017834818',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'fantasy_id' => 37394521,
    ]);
    $remainingPlayer = Player::factory()->create(['fantasy_id' => 988]);
    $soldPlayer = Player::factory()->create(['fantasy_id' => 3040]);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
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

    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();

    expect(ManagerPlayer::query()->count())->toBe(1)
        ->and(ManagerPlayer::query()->where('player_id', $remainingPlayer->id)->exists())->toBeTrue()
        ->and(ManagerPlayer::query()->where('player_id', $soldPlayer->id)->exists())->toBeFalse();
});

function fakeLeagueTeamWithMoney(mixed $teamMoney): void
{
    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueTeamRequest::class => MockResponse::make(['teamMoney' => $teamMoney, 'players' => []]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);
}

function currentSeasonWithManager(): SeasonManager
{
    Cache::forget('la_liga_fantasy.access_token');
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);

    return SeasonManager::factory()->create(['season_id' => $season->id]);
}

test('stores a teamMoney snapshot for the connected account', function (): void {
    $seasonManager = currentSeasonWithManager();
    fakeLeagueTeamWithMoney(254969545);

    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();

    $snapshot = ManagerBalanceSnapshot::query()->sole();
    expect($snapshot->season_manager_id)->toBe($seasonManager->id)
        ->and($snapshot->money)->toBe(254969545)
        ->and($snapshot->captured_at->diffInSeconds(now()))->toBeLessThan(5);
});

test('stores no snapshot when teamMoney is null (a rival)', function (): void {
    currentSeasonWithManager();
    fakeLeagueTeamWithMoney(null);

    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();

    expect(ManagerBalanceSnapshot::query()->count())->toBe(0);
});

test('stores at most one snapshot per manager and hour', function (): void {
    currentSeasonWithManager();
    fakeLeagueTeamWithMoney(100);

    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();
    $this->travel(59)->minutes();
    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();
    expect(ManagerBalanceSnapshot::query()->count())->toBe(1);

    $this->travel(2)->minutes();
    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();
    expect(ManagerBalanceSnapshot::query()->count())->toBe(2);
});

function fakeLeagueTeamWithClause(int $clause, string $lockedEnd, int $marketValue): void
{
    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')->andReturn('header.eyJleHAiOjE3ODc0MTc3NTB9.signature');

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueTeamRequest::class => MockResponse::make(['teamMoney' => null, 'players' => [[
            'buyoutClause' => $clause,
            'buyoutClauseLockedEndTime' => $lockedEnd,
            'isShielded' => false,
            'playerMaster' => ['id' => '988', 'marketValue' => $marketValue],
        ]]]),
    ]));

    app()->instance(LaLigaLoginConnector::class, $loginConnector);
    app()->instance(LaLigaFantasyConnector::class, $fantasyConnector);
}

test('stores a clause snapshot only when the clause or its lock changes', function (): void {
    $seasonManager = currentSeasonWithManager();
    $player = Player::factory()->create(['fantasy_id' => 988]);

    fakeLeagueTeamWithClause(5_000_000, '2026-09-15T20:00:00+02:00', 4_000_000);
    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();
    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();
    expect(ManagerPlayerClauseSnapshot::query()->count())->toBe(1);

    fakeLeagueTeamWithClause(9_123_456, '2026-09-15T20:00:00+02:00', 4_500_000);
    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();

    $latest = ManagerPlayerClauseSnapshot::query()->latest('id')->first();
    expect(ManagerPlayerClauseSnapshot::query()->count())->toBe(2)
        ->and($latest->season_manager_id)->toBe($seasonManager->id)
        ->and($latest->player_id)->toBe($player->id)
        ->and($latest->buyout_clause)->toBe(9_123_456)
        ->and($latest->market_value)->toBe(4_500_000);

    fakeLeagueTeamWithClause(9_123_456, '2026-10-01T20:00:00+02:00', 4_500_000);
    $this->artisan(SyncCurrentSeasonManagerPlayers::class)->assertSuccessful();
    expect(ManagerPlayerClauseSnapshot::query()->count())->toBe(3);
});
