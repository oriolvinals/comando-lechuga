<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use App\Services\PlayerMarketMetrics;
use Illuminate\Support\Facades\DB;

function signalsSeason(): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDays(60),
        'end_date' => now()->addDays(200),
        'current_week' => 2,
    ]);
}

function signalsNextFixture(Season $season, Team $local, Team $guest, FixtureState $state = FixtureState::Scheduled): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'state' => $state,
        'team_local_id' => $local->id,
        'team_guest_id' => $guest->id,
        'date' => now()->addDays(2),
    ]);
}

test('adds the next start from FútbolFantasy to every player in the list', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $rival = Team::factory()->create(['main_name' => 'Rival FC']);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $fixture = signalsNextFixture($season, $rival, $team);
    FixtureLineupProbability::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'probability' => 85,
        'predicted_starter' => true,
        'fetched_at' => now()->subHour(),
    ]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start.fixture_id', $fixture->id);
    $response->assertJsonPath('data.0.next_start.week_number', 2);
    $response->assertJsonPath('data.0.next_start.date', $fixture->date->toIso8601String());
    $response->assertJsonPath('data.0.next_start.opponent.name', 'Rival FC');
    $response->assertJsonPath('data.0.next_start.is_home', false);
    $response->assertJsonPath('data.0.next_start.probability', 85);
    $response->assertJsonPath('data.0.next_start.predicted_starter', true);
    $response->assertJsonPath('data.0.next_start.confirmed_starter', null);
    $response->assertJsonPath('data.0.next_start.source', 'futbolfantasy');
    $response->assertJsonPath('data.0.next_start.is_stale', false);
});

test('has a null next start, never a zero, when there is no data', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    signalsNextFixture($season, $team, Team::factory()->create());

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start', null);
});

test('has a null next start when the team\'s next match is postponed', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $postponed = signalsNextFixture($season, $team, Team::factory()->create(), FixtureState::Postponed);
    FixtureLineupProbability::factory()->create(['fixture_id' => $postponed->id, 'player_id' => $player->id, 'probability' => 90]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start', null);
});

test('says the worldcup26 lineup confirmed the start once it has', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $fixture = signalsNextFixture($season, $team, Team::factory()->create());
    FixtureLineupProbability::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'probability' => 40]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $team->id, 'starter' => true]);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_start.source', 'worldcup26');
    $response->assertJsonPath('data.0.next_start.confirmed_starter', true);
});

test('adds the next start to market listings and to the player ficha', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id, 'position' => PlayerPosition::Striker]);
    $fixture = signalsNextFixture($season, $team, Team::factory()->create());
    FixtureLineupProbability::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'probability' => 70]);
    MarketPlayer::factory()->create(['player_id' => $player->id, 'expires_at' => now()->addHours(3)]);

    $this->getJson('/api/market')->assertJsonPath('data.0.player.next_start.probability', 70);
    $this->getJson("/api/players/{$player->id}")->assertJsonPath('data.next_start.probability', 70);
});

test('rates each next fixture by the rival\'s real standings position', function (): void {
    $season = signalsSeason();
    $alpha = Team::factory()->create(['main_name' => 'Alpha FC']);
    $bravo = Team::factory()->create(['main_name' => 'Bravo FC']);
    $charlie = Team::factory()->create(['main_name' => 'Charlie FC']);
    $delta = Team::factory()->create(['main_name' => 'Delta FC']);
    $season->teams()->attach([$alpha->id, $bravo->id, $charlie->id, $delta->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $alpha->id, 'position' => PlayerPosition::Striker]);
    $fixture = signalsNextFixture($season, $alpha, $delta);

    $response = $this->getJson('/api/players');

    $response->assertOk();
    $response->assertJsonPath('data.0.next_fixtures.0.fixture_id', $fixture->id);
    $response->assertJsonPath('data.0.next_fixtures.0.date', $fixture->date->toIso8601String());
    $response->assertJsonPath('data.0.next_fixtures.0.rival_position', 4);
    $response->assertJsonPath('data.0.next_fixtures.0.difficulty_variant', 'attack');
    // json_encode writes 4.0 as 4, so compare loosely.
    expect($response->json('data.0.next_fixtures.0.difficulty'))->toEqual(4.0);
});

test('adds the 30-day value multiple, points per million with rank and the owner\'s gain', function (): void {
    $season = signalsSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 50_000_000, 'points' => 20]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 10_000_000, 'points' => 10]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(30)->toDateString(), 'value' => 25_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString(), 'value' => 50_000_000]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'amount' => 45_000_000,
        'occurred_at' => now()->subDays(5),
    ]);

    $response = $this->getJson("/api/players/{$player->id}");

    $response->assertOk();
    expect($response->json('data.value_trend_30d.multiple'))->toEqual(2.0);
    $response->assertJsonPath('data.value_trend_30d.value', 25_000_000);
    $response->assertJsonPath('data.points_per_million.value', 0.4);
    $response->assertJsonPath('data.points_per_million.rank', 2);
    $response->assertJsonPath('data.points_per_million.ranked', 2);
    $response->assertJsonPath('data.owner_gain.amount', 5_000_000);
    $response->assertJsonPath('data.owner_gain.paid', 45_000_000);
    $response->assertJsonPath('data.owner_gain.type', 'signing');
});

test('sorts players by market trend strength', function (): void {
    signalsSeason();
    $falling = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_trend' => MarketTrend::FallSteady]);
    $flat = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_trend' => null]);
    $rising = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_trend' => MarketTrend::RiseAcceleratingSharply]);

    $this->getJson('/api/players?sort=trend')
        ->assertOk()
        ->assertJsonPath('data.0.id', $rising->id)
        ->assertJsonPath('data.1.id', $flat->id)
        ->assertJsonPath('data.2.id', $falling->id);

    $this->getJson('/api/players?sort=trend&direction=asc')
        ->assertJsonPath('data.0.id', $falling->id);
});

test('sorts players by points per million', function (): void {
    signalsSeason();
    $pricey = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 50_000_000, 'points' => 20]);
    $bargain = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 10_000_000, 'points' => 10]);
    $blank = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 5_000_000, 'points' => 0]);

    $this->getJson('/api/players?sort=points_per_million')
        ->assertOk()
        ->assertJsonPath('data.0.id', $bargain->id)
        ->assertJsonPath('data.1.id', $pricey->id)
        ->assertJsonPath('data.2.id', $blank->id);
});

test('filters free agents or owned players', function (): void {
    $season = signalsSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $owned->id]);
    $free = Player::factory()->create(['status' => PlayerStatus::Ok]);

    $this->getJson('/api/players?free=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $free->id);
    $this->getJson('/api/players?free=false')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $owned->id);
});

test('filters players by a market value range', function (): void {
    signalsSeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 5_000_000]);
    $middle = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 20_000_000]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 80_000_000]);

    $this->getJson('/api/players?min_value=10000000&max_value=30000000')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $middle->id);
});

test('filters players by the start probability of their next match only', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    $next = signalsNextFixture($season, $team, Team::factory()->create());
    $later = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'state' => FixtureState::Scheduled,
        'team_local_id' => $team->id,
        'date' => now()->addDays(9),
    ]);
    $likely = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $doubt = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $likely->id, 'probability' => 85]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $doubt->id, 'probability' => 40]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $later->id, 'player_id' => $doubt->id, 'probability' => 95]);

    $this->getJson('/api/players?min_start_probability=70')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $likely->id);
});

test('rejects invalid new filters with a 422', function (string $query, string $parameter): void {
    signalsSeason();

    $this->getJson("/api/players?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$parameter]);
})->with([
    'probability over 100' => ['min_start_probability=150', 'min_start_probability'],
    'free not boolean' => ['free=maybe', 'free'],
    'negative value' => ['min_value=-1', 'min_value'],
    'unknown sort' => ['sort=average', 'sort'],
]);

test('keeps the number of queries flat however many players the page shows', function (): void {
    $season = signalsSeason();
    $team = Team::factory()->create();
    signalsNextFixture($season, $team, Team::factory()->create());

    $countQueries = function (): int {
        // MatchDifficulty (and TeamStrength) are bound scoped: without this,
        // their per-request memos would carry over into the second
        // measurement below (a test-only artifact — a real request always
        // gets a fresh instance) and hide any real N+1.
        app()->forgetScopedInstances();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/players')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // A fixed position keeps the difficulty variant (and so which
    // MatchDifficulty branches run) identical between both measurements —
    // a random position could make one draw skip the absence adjustment
    // (DifficultyVariant::Defense) and the other not, for a flaky count.
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id, 'market_value' => 10_000_000, 'points' => 10, 'position' => PlayerPosition::Striker]);
    $withOnePlayer = $countQueries();

    Player::factory()->count(10)->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id, 'market_value' => 10_000_000, 'points' => 10, 'position' => PlayerPosition::Striker]);
    $withElevenPlayers = $countQueries();

    expect($withElevenPlayers)->toBe($withOnePlayer);
});

test('ranks points per million among the league, ties sharing a rank', function (): void {
    $season = signalsSeason();
    [$pricey, $bargain, $tiedBargain, $pointless, $unvalued] = [
        Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 50_000_000, 'points' => 20]),
        Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 10_000_000, 'points' => 10]),
        Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 20_000_000, 'points' => 20]),
        Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 5_000_000, 'points' => 0]),
        Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 0, 'points' => 8]),
    ];
    $metrics = app(PlayerMarketMetrics::class);

    $batch = $metrics->pointsPerMillionForPlayers(collect([$pricey, $bargain, $tiedBargain, $pointless, $unvalued]), $season);

    expect($batch)->toBe([
        $pricey->id => ['value' => 0.4, 'rank' => 3, 'ranked' => 3],
        $bargain->id => ['value' => 1.0, 'rank' => 1, 'ranked' => 3],
        $tiedBargain->id => ['value' => 1.0, 'rank' => 1, 'ranked' => 3],
        $pointless->id => ['value' => 0.0, 'rank' => null, 'ranked' => 3],
        $unvalued->id => null,
    ])
        ->and($metrics->pointsPerMillion($pricey, $season))->toBe(['value' => 0.4, 'rank' => 3, 'ranked' => 3])
        ->and($metrics->pointsPerMillion($unvalued, $season))->toBeNull();
});
