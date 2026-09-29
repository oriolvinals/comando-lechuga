<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\LeagueCloud;
use Illuminate\Support\Facades\DB;

function cloudSeason(): Season
{
    return Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
}

test('one row per listed league player of the season, sorted by points', function (): void {
    $season = cloudSeason();
    $low = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 10]);
    $high = Player::factory()->create(['status' => PlayerStatus::Doubtful, 'points' => 90]);
    Player::factory()->create(['status' => PlayerStatus::OutOfLeague]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'fantasy_id' => null]);
    $noSeason = Player::factory()->create(['status' => PlayerStatus::Ok]);
    PlayerSeason::query()->where('player_id', $noSeason->id)->delete();

    $rows = app(LeagueCloud::class)->rows($season, 1);

    expect(array_column($rows, 'id'))->toBe([$high->id, $low->id]);
});

test('a row carries the figures the tracks, search and hover card need', function (): void {
    $season = cloudSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create([
        'status' => PlayerStatus::Ok, 'points' => 50, 'average_points' => 5.5,
        'market_value' => 25_000_000, 'market_value_difference' => -120_000, 'position' => 'midfield',
    ]);
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(30)->toDateString(), 'value' => 20_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString(), 'value' => 25_000_000]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Scheduled, 'date' => now()->addDay(),
        'team_local_id' => $player->team_id,
    ]);
    FixtureLineupProbability::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'probability' => 75]);

    $row = app(LeagueCloud::class)->rows($season, 1)[0];

    expect($row)->toMatchArray([
        'id' => $player->id,
        'name' => $player->nickname,
        'position' => 'midfield',
        'team_short' => $player->team->short_name,
        'owner_id' => $manager->id,
        'points' => 50,
        'average_points' => 5.5,
        'ppm' => 2.0,
        'start_probability' => 75,
        'value_trend_30d' => 1.25,
        'value' => 25_000_000,
        'difference' => -120_000,
    ]);
});

test('a free player without value, history or start data has null metrics', function (): void {
    $season = cloudSeason();
    Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 0, 'points' => 0]);

    $row = app(LeagueCloud::class)->rows($season, 1)[0];

    expect($row['owner_id'])->toBeNull()
        ->and($row['ppm'])->toBeNull()
        ->and($row['start_probability'])->toBeNull()
        ->and($row['value_trend_30d'])->toBeNull();
});

test('a confirmed lineup counts as 100 or 0 and wins over the probability', function (): void {
    expect(LeagueCloud::startValue(null))->toBeNull()
        ->and(LeagueCloud::startValue(['probability' => 40, 'confirmed_starter' => null]))->toBe(40)
        ->and(LeagueCloud::startValue(['probability' => 40, 'confirmed_starter' => true]))->toBe(100)
        ->and(LeagueCloud::startValue(['probability' => 95, 'confirmed_starter' => false]))->toBe(0);
});

test('the rows are cached for fifteen minutes', function (): void {
    $season = cloudSeason();
    Player::factory()->create(['status' => PlayerStatus::Ok]);
    $cloud = app(LeagueCloud::class);

    expect($cloud->rows($season, 1))->toHaveCount(1);

    Player::factory()->create(['status' => PlayerStatus::Ok]);
    DB::enableQueryLog();
    expect($cloud->rows($season, 1))->toHaveCount(1);
    expect(DB::getQueryLog())->toBe([]);

    $this->travel(16)->minutes();
    expect($cloud->rows($season, 1))->toHaveCount(2);
});

test('the start probability is the one for the comparison week, not a pending match of the live jornada', function (): void {
    $season = cloudSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $fixtureFor = fn (int $week, int $days): Fixture => Fixture::factory()->create([
        'season_id' => $season->id, 'state' => FixtureState::Scheduled, 'week_number' => $week,
        'date' => now()->addDays($days), 'team_local_id' => $player->team_id,
    ]);
    FixtureLineupProbability::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixtureFor(5, 1)->id, 'probability' => 90]);
    FixtureLineupProbability::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixtureFor(6, 5)->id, 'probability' => 30]);

    expect(app(LeagueCloud::class)->rows($season, 6)[0]['start_probability'])->toBe(30);
});
