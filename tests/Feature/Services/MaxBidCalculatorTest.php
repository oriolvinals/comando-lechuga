<?php

use App\Enums\BadScoreRule;
use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\MaxBidStatus;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Models\ValueForecast;
use App\Services\LeagueStandings;
use App\Services\MatchDifficulty;
use App\Services\MaxBidCalculator;
use App\Services\MaxBidParameters;

beforeEach(function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);

    // A big flat "rest of the market", so the market index stays ~0 and the
    // player under test is measured on his own momentum.
    maxBidPlayer($this->season, array_fill(0, 10, 1_000_000_000));
});

/**
 * A current-season player with one market value per day, the last one today.
 * A midfielder unless `position` says otherwise, so his rivals are rated
 * with a fixed difficulty variant (attack).
 *
 * @param  list<int>  $values  oldest first
 * @param  array<string, mixed>  $attributes
 */
function maxBidPlayer(Season $season, array $values, array $attributes = []): Player
{
    $team = isset($attributes['team_id']) ? Team::query()->findOrFail($attributes['team_id']) : Team::factory()->create();
    $season->teams()->syncWithoutDetaching([$team->id]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, 'position' => PlayerPosition::Midfield, ...$attributes]);

    foreach (array_values($values) as $index => $value) {
        PlayerMarket::factory()->create([
            'player_id' => $player->id,
            'date' => now()->subDays(count($values) - 1 - $index)->toDateString(),
            'value' => $value,
        ]);
    }

    return $player;
}

test('a rising player is profitable with a bid above his value', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Profitable)
        ->and($estimate->value)->toBe(10_300_000)
        ->and($estimate->momentumIncrement)->toEqualWithDelta(100_000, 0.01)
        ->and($estimate->projection)->toHaveCount(15)
        ->and($estimate->bid)->toBeGreaterThan(10_300_000);
});

test('a falling player is unprofitable even though a lucky offer could beat his value', function (): void {
    $player = maxBidPlayer($this->season, [10_300_000, 10_200_000, 10_100_000, 10_000_000]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Unprofitable)
        ->and($estimate->bid)->toBeNull()
        ->and($estimate->projection[14])->toBeLessThan(10_000_000);
});

test('an injured, suspended or out-of-league player is unavailable', function (PlayerStatus $status): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => $status]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Unavailable)
        ->and($estimate->value)->toBe(10_300_000)
        ->and($estimate->bid)->toBeNull();
})->with([PlayerStatus::Injured, PlayerStatus::Suspended, PlayerStatus::OutOfLeague]);

test('fewer than four days of market history is not enough data', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000]);

    expect(app(MaxBidCalculator::class)->estimate($player, $this->season)->status)->toBe(MaxBidStatus::NoData);
});

test('uses the last four available values when a day is missing', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000]);
    PlayerMarket::query()->where('player_id', $player->id)->whereDate('date', now()->subDays(2))->delete();

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Profitable)
        ->and($estimate->momentumIncrement)->toEqualWithDelta((10_400_000 - 10_000_000) / 3, 0.01);
});

test('the market index is neutral when the start of the window has no data', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000]);
    // No player at all has a value 3 days ago — as in the first days of a season.
    PlayerMarket::query()->whereDate('date', now()->subDays(3))->delete();

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->marketAdjustment)->toBe(0.0);
});

test('the market index ignores a player valued at only one end of the window', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    $before = app(MaxBidCalculator::class)->estimate($player, $this->season)->marketAdjustment;

    // Present only today (the end of the window), never 3 days ago — a player
    // who just joined the league. If it leaked into the index, its huge value
    // would swamp the flat anchor and change the index.
    maxBidPlayer($this->season, [50_000_000_000]);

    $after = app(MaxBidCalculator::class)->estimate($player, $this->season)->marketAdjustment;

    expect($after)->toBe($before);
});

test('the reference date ignores anything after it', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 9_000_000, 8_000_000]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season, now()->subDays(2));

    expect($estimate->value)->toBe(10_300_000)
        ->and($estimate->status)->toBe(MaxBidStatus::Profitable);
});

test('serializes for the ficha', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    $array = app(MaxBidCalculator::class)->estimate($player, $this->season)->toArray();

    expect($array['status'])->toBe('profitable')
        ->and($array['projected_day14'])->toBe($array['projection'][14])
        ->and($array['bid_premium'])->toBeGreaterThan(0)
        ->and($array['confidence'])->toBe(MaxBidCalculator::CONFIDENCE)
        ->and($array['lock_days'])->toBe(MaxBidCalculator::LOCK_DAYS);
});

test('a custom confidence is used to solve the bid and is reflected in the estimate', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    $estimateAt90 = app(MaxBidCalculator::class)->estimate($player, $this->season, confidence: 0.9);
    $estimateAt75 = app(MaxBidCalculator::class)->estimate($player, $this->season, confidence: 0.75);

    expect($estimateAt90->confidence)->toBe(0.9)
        ->and($estimateAt90->bid)->toBeLessThan($estimateAt75->bid);
});

test('an unavailable or unprofitable estimate still reports the requested confidence and lock days', function (): void {
    $unavailable = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => PlayerStatus::Injured]);
    $falling = maxBidPlayer($this->season, [10_300_000, 10_200_000, 10_100_000, 10_000_000]);

    $unavailableEstimate = app(MaxBidCalculator::class)->estimate($unavailable, $this->season, confidence: 0.9);
    $fallingEstimate = app(MaxBidCalculator::class)->estimate($falling, $this->season, confidence: 0.9);

    expect($unavailableEstimate->confidence)->toBe(0.9)
        ->and($unavailableEstimate->lockDays)->toBe(MaxBidCalculator::LOCK_DAYS)
        ->and($fallingEstimate->confidence)->toBe(0.9)
        ->and($fallingEstimate->lockDays)->toBe(MaxBidCalculator::LOCK_DAYS);
});

/**
 * A finished fixture of `$team` on `$daysAgo`, with the player's lineup row
 * when `$minutes` is given (null = not in the lineup at all).
 */
function playedFixture(Season $season, Player $player, int $daysAgo, ?int $minutes, bool $starter = true, ?int $points = 5): Fixture
{
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'date' => now()->subDays($daysAgo)->setTime(18, 0),
        'team_local_id' => $player->team_id,
        'team_guest_id' => Team::factory()->create()->id,
        'local_score' => 1,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);

    if ($minutes !== null) {
        FixtureLineup::factory()->create([
            'fixture_id' => $fixture->id,
            'player_id' => $player->id,
            'team_id' => $player->team_id,
            'starter' => $starter,
            'fantasy_points' => $points,
            'fantasy_stats' => ['mins_played' => [$minutes, 2]],
        ]);
    }

    return $fixture;
}

test('participation weighs starts and minutes of the team\'s last three matches, newest first', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    playedFixture($this->season, $player, 21, 90);          // older than the last three — ignored
    playedFixture($this->season, $player, 14, 90);          // 0,2 × 1
    playedFixture($this->season, $player, 7, 45, false);    // 0,3 × (0 + 0,5·0,5) = 0,075
    playedFixture($this->season, $player, 2, null);         // 0,5 × 0 — not in the lineup

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->participation)->toEqualWithDelta(0.275, 0.0001)
        ->and($estimate->recentParticipation)->toBe([
            ['starter' => false, 'minutes' => 0],
            ['starter' => false, 'minutes' => 45],
            ['starter' => true, 'minutes' => 90],
        ]);
});

test('a bench player gets the bench factor of a positive daily increment', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    playedFixture($this->season, $player, 2, null);
    // Without the one-bench rule, which would otherwise cap this benched player's increment at 0.
    $parameters = new MaxBidParameters(benchesBeforeUnprofitable: 0);

    $estimate = (new MaxBidCalculator(app(LeagueStandings::class), app(MatchDifficulty::class), $parameters))->estimate($player, $this->season);

    expect($estimate->dailyIncrement)->toEqualWithDelta(
        ($estimate->momentumIncrement + $estimate->marketAdjustment + $estimate->sportAdjustment) * $parameters->benchIncrementFactor,
        0.01,
    );
});

test('form compares the last three points with the season average, null points counting as zero', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    playedFixture($this->season, $player, 20, 90, points: 2);
    playedFixture($this->season, $player, 13, 90, points: 10);
    playedFixture($this->season, $player, 6, 90, points: 10);
    playedFixture($this->season, $player, 1, 90, points: null);

    // average over all four = (2 + 10 + 10 + 0) / 4 = 5,5; last three = 20 / 3 = 6,67
    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->form)->toEqualWithDelta((20 / 3 - 5.5) / 5.5, 0.0001);
});

test('each upcoming rival weighs by how soon the match is', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    $leader = Team::factory()->create();
    $bottom = Team::factory()->create();
    $this->season->teams()->syncWithoutDetaching([$leader->id, $bottom->id]);
    // The leader also has the most valuable squad, so its strength makes it a hard rival.
    maxBidPlayer($this->season, array_fill(0, 4, 2_000_000_000), ['team_id' => $leader->id]);
    Fixture::factory()->create([
        'season_id' => $this->season->id, 'week_number' => 1, 'date' => now()->subDays(5),
        'team_local_id' => $leader->id, 'team_guest_id' => $bottom->id,
        'local_score' => 3, 'guest_score' => 0, 'state' => FixtureState::Finished,
    ]);
    foreach ([[1, $leader], [20, $bottom]] as [$inDays, $rival]) {
        Fixture::factory()->create([
            'season_id' => $this->season->id, 'week_number' => 8, 'date' => now()->addDays($inDays)->setTime(18, 0),
            'team_local_id' => $player->team_id, 'team_guest_id' => $rival->id, 'state' => FixtureState::Scheduled,
        ]);
    }

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);
    [$soon, $later] = $estimate->upcomingRivals;
    $leaderFixture = Fixture::query()->where('team_guest_id', $leader->id)->where('state', FixtureState::Scheduled)->sole();

    expect($soon['team']->id)->toBe($leader->id)
        ->and($soon['position'])->toBe(1)
        ->and($soon['days_until'])->toBe(1)
        // The midfielder's rival ease (−1 hard … +1 easy): MatchDifficulty's attack variant, "now".
        ->and($soon['difficulty'])->toBe(app(MatchDifficulty::class)->for($leaderFixture, $player->team_id, DifficultyVariant::Attack)->rivalEase)
        ->and($soon['difficulty'])->toBeLessThan(0.0)
        ->and($soon['weight'])->toEqualWithDelta(0.5 ** (1 / 7), 0.0001)
        ->and($later['days_until'])->toBe(20)
        ->and($later['weight'])->toEqualWithDelta(0.5 ** (20 / 7), 0.0001)
        ->and($estimate->rivalsEffect)->toBeLessThan(0.0);
});

/**
 * A strong-squad rival that beat a weak one, and a scheduled match of
 * `$player`'s team against it `$inDays` from now, at home.
 *
 * @param  list<int>  $rivalValues  its star's daily values, oldest first, the last one today
 */
function strongRivalFixture(Season $season, Player $player, int $inDays, array $rivalValues = [2_000_000_000, 2_000_000_000, 2_000_000_000, 2_000_000_000]): Fixture
{
    $strong = Team::factory()->create();
    $weak = Team::factory()->create();
    $season->teams()->syncWithoutDetaching([$strong->id, $weak->id]);
    maxBidPlayer($season, $rivalValues, ['team_id' => $strong->id]);
    Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 1, 'date' => now()->subDays(5),
        'team_local_id' => $strong->id, 'team_guest_id' => $weak->id,
        'local_score' => 4, 'guest_score' => 0, 'state' => FixtureState::Finished,
    ]);

    return Fixture::factory()->create([
        'season_id' => $season->id, 'week_number' => 8, 'date' => now()->addDays($inDays)->setTime(18, 0),
        'team_local_id' => $player->team_id, 'team_guest_id' => $strong->id, 'state' => FixtureState::Scheduled,
    ]);
}

test('each upcoming rival is rated with the difficulty variant the player\'s position faces', function (PlayerPosition $position, DifficultyVariant $variant): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['position' => $position]);
    $fixture = strongRivalFixture($this->season, $player, 2);

    $rival = app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->upcomingRivals[0];

    expect($rival['difficulty'])->toBe(app(MatchDifficulty::class)->for($fixture, $player->team_id, $variant)->rivalEase);
})->with([
    'goalkeeper faces the attack' => [PlayerPosition::Goalkeeper, DifficultyVariant::Defense],
    'striker faces the defense' => [PlayerPosition::Striker, DifficultyVariant::Attack],
]);

test('a past reference date rates the upcoming rivals at that date', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000]);
    // The rival's star was cheap two days ago and is a 2 000 M star today.
    $fixture = strongRivalFixture($this->season, $player, 2, [1_000_000, 1_000_000, 1_000_000, 2_000_000_000]);
    $at = now()->subDays(2);

    $rival = app(MaxBidCalculator::class)->gatherInputs($player, $this->season, $at)->upcomingRivals[0];

    // Its squad value then made it a weaker rival than it is today.
    expect($rival['difficulty'])->toBe(app(MatchDifficulty::class)->for($fixture, $player->team_id, DifficultyVariant::Attack, $at->endOfDay())->rivalEase)
        ->and($rival['difficulty'])->not->toBe(app(MatchDifficulty::class)->for($fixture, $player->team_id, DifficultyVariant::Attack)->rivalEase);
});

test('gathering the inputs reads the start probability of the player\'s next match', function (?int $probability, ?bool $confirmed, ?float $expected): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    $next = strongRivalFixture($this->season, $player, 2);
    $later = strongRivalFixture($this->season, $player, 9);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id, 'fixture_id' => $next->id, 'probability' => $probability,
        'confirmed_starter' => $confirmed, 'fetched_at' => now()->subHour(),
    ]);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id, 'fixture_id' => $later->id, 'probability' => 5, 'fetched_at' => now()->subHour(),
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->nextStartProbability)->toBe($expected);
})->with([
    'a predicted %' => [80, null, 0.8],
    'a confirmed starter beats the %' => [20, true, 1.0],
    'a confirmed substitute beats the %' => [90, false, 0.0],
    'no % and no confirmation' => [null, null, null],
]);

test('the start probability ignores a row fetched after the reference moment and is null without one', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000]);
    $next = strongRivalFixture($this->season, $player, 2);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id, 'fixture_id' => $next->id, 'probability' => 70, 'fetched_at' => now()->subHour(),
    ]);
    $calculator = app(MaxBidCalculator::class);

    expect($calculator->gatherInputs($player, $this->season, now()->subDay())->nextStartProbability)->toBeNull()
        ->and($calculator->gatherInputs($player, $this->season)->nextStartProbability)->toBe(0.7)
        ->and($calculator->gatherInputs(maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]), $this->season)->nextStartProbability)->toBeNull();
});

test('a team with no upcoming fixture has a neutral rivals effect', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->upcomingRivals)->toBe([])
        ->and($estimate->rivalsEffect)->toBe(0.0)
        ->and($estimate->sportScore)->toBe(0.0);
});

test('a doubtful player has a sport score of at most −0,5', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => PlayerStatus::Doubtful]);

    expect(app(MaxBidCalculator::class)->estimate($player, $this->season)->sportScore)->toBeLessThanOrEqual(-0.5);
});

test('the formula with default parameters on the gathered inputs equals estimate()', function (): void {
    $calculator = app(MaxBidCalculator::class);
    $leader = Team::factory()->create();
    $this->season->teams()->syncWithoutDetaching([$leader->id]);

    $rising = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    $falling = maxBidPlayer($this->season, [10_300_000, 10_200_000, 10_100_000, 10_000_000]);
    $injured = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => PlayerStatus::Injured]);
    $short = maxBidPlayer($this->season, [10_000_000, 10_100_000]);
    $doubtful = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => PlayerStatus::Doubtful]);
    $bench = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    playedFixture($this->season, $bench, 9, 90, points: 8);
    playedFixture($this->season, $bench, 2, null);
    $regular = maxBidPlayer($this->season, [10_000_000, 10_150_000, 10_200_000, 10_400_000]);
    playedFixture($this->season, $regular, 8, 90, points: 2);
    playedFixture($this->season, $regular, 1, 70, points: 12);
    Fixture::factory()->create([
        'season_id' => $this->season->id, 'week_number' => 8, 'date' => now()->addDays(3)->setTime(18, 0),
        'team_local_id' => $regular->team_id, 'team_guest_id' => $leader->id, 'state' => FixtureState::Scheduled,
    ]);

    foreach ([$rising, $falling, $injured, $short, $doubtful, $bench, $regular] as $player) {
        $fromInputs = MaxBidCalculator::estimateFromInputs($calculator->gatherInputs($player, $this->season), new MaxBidParameters);

        expect($fromInputs)->toEqual($calculator->estimate($player, $this->season));
    }
});

test('a calculator built with a different decay projects differently', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    $slower = new MaxBidCalculator(app(LeagueStandings::class), app(MatchDifficulty::class), new MaxBidParameters(incrementDecayBreak: 0.8, incrementDecayMatchweek: 0.8));

    $default = app(MaxBidCalculator::class)->estimate($player, $this->season);
    $custom = $slower->estimate($player, $this->season);

    expect($custom->dailyIncrement)->toBe($default->dailyIncrement)
        ->and($custom->projection[14])->toBeLessThan($default->projection[14]);
});

test('gathering the inputs of a player benched in the last match records it newest first', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    playedFixture($this->season, $player, 9, 90, points: 8);
    playedFixture($this->season, $player, 2, 0, starter: false, points: 1);

    $inputs = app(MaxBidCalculator::class)->gatherInputs($player, $this->season);
    $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters(benchesBeforeUnprofitable: 1));

    expect($inputs->recentParticipation[0])->toBe(['starter' => false, 'minutes' => 0])
        ->and($inputs->latestPoints())->toBe(1)
        ->and($inputs->seasonPointsAverage)->toEqual(4.5)
        ->and($estimate->status)->toBe(MaxBidStatus::Unprofitable);
});

test('pins the formula on a sport-rich scenario with explicit parameters', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_150_000, 10_200_000, 10_400_000]);
    playedFixture($this->season, $player, 23, 90, points: 0);
    playedFixture($this->season, $player, 16, 90, points: 2);
    playedFixture($this->season, $player, 9, 70, points: 12);
    playedFixture($this->season, $player, 2, 90, starter: false, points: 6);
    $leader = Team::factory()->create();
    $bottom = Team::factory()->create();
    $this->season->teams()->syncWithoutDetaching([$leader->id, $bottom->id]);
    foreach ([12, 5] as $daysAgo) {
        Fixture::factory()->create([
            'season_id' => $this->season->id, 'week_number' => 1, 'date' => now()->subDays($daysAgo),
            'team_local_id' => $leader->id, 'team_guest_id' => $bottom->id,
            'local_score' => 3, 'guest_score' => 0, 'state' => FixtureState::Finished,
        ]);
    }
    foreach ([[2, $leader], [9, $bottom]] as [$inDays, $rival]) {
        Fixture::factory()->create([
            'season_id' => $this->season->id, 'week_number' => 8, 'date' => now()->addDays($inDays)->setTime(18, 0),
            'team_local_id' => $player->team_id, 'team_guest_id' => $rival->id, 'state' => FixtureState::Scheduled,
        ]);
    }
    // The specified model, spelled out so a change of defaults doesn't move these numbers.
    $parameters = new MaxBidParameters(
        incrementDecayBreak: 0.9,
        incrementDecayMatchweek: 0.9,
        sportDailyRate: 0.01,
        formWeight: 0.4,
        participationWeight: 0.3,
        rivalsWeight: 0.3,
        proximityHalfLifeDays: 7.0,
        benchParticipation: 0.4,
        benchIncrementFactor: 0.5,
        benchesBeforeUnprofitable: 0,
        badScoreRule: BadScoreRule::Off,
        startProbabilityWeight: 0.5,
        confidenceCalibration: [],
        incrementShrink: 1.0,
    );

    $estimate = (new MaxBidCalculator(app(LeagueStandings::class), app(MatchDifficulty::class), $parameters))->estimate($player, $this->season);

    // form = (20/3 − 5) / 5; participation = 0,5·0,25 + 0,3·(0,5 + 0,5·70/90) + 0,2·1 (no start probability).
    // Rivals, rated by MatchDifficulty for a midfielder (attack variant: the rival's defensive
    // solidity), both at the player's home (+0,4). Neither has market values, so both are left
    // out of the squad value z (z = 0), and each played 2 matches (shrink weight 2/(2+8) = 0,2):
    // - leader (won both 3-0): solidity = 0,8·0 + 0,2·(0,7·0,408 + 0,3·0,356) = 0,078;
    //   ease e = −0,078 + 0,4 = 0,322 → difficulty round(5 − 2,5·0,322) = 4,2 → rivalEase 0,16;
    // - bottom (lost both 0-3): solidity = −0,084; e = 0,484 → difficulty 3,8 → rivalEase 0,24.
    // rivalsEffect = (0,5^(2/7)·0,16 + 0,5^(9/7)·0,24) / 3 = 0,076564633;
    // score = 0,5^(2/7)·(0,4·form + 0,3·(2·participation − 1)) + 0,3·3·rivalsEffect = 0,284929814.
    expect($estimate->status)->toBe(MaxBidStatus::Profitable)
        ->and($estimate->form)->toEqualWithDelta(1 / 3, 1e-9)
        ->and($estimate->participation)->toEqualWithDelta(0.716666667, 1e-9)
        ->and(array_column($estimate->upcomingRivals, 'difficulty'))->toBe([0.16, 0.24])
        ->and($estimate->rivalsEffect)->toEqualWithDelta(0.076564633, 1e-9)
        ->and($estimate->sportScore)->toEqualWithDelta(0.284929814, 1e-9)
        ->and($estimate->dailyIncrement)->toEqualWithDelta(161_593.097, 0.001)
        ->and($estimate->projection[14])->toBe(11_521_632)
        ->and($estimate->bid)->toBe(12_141_660);
});

test('gathering the inputs records the team\'s points in its last three matches, newest first', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    playedFixture($this->season, $player, 16, 90);    // 1-1 at home: a draw
    foreach ([[9, 0, 2], [2, 3, 1]] as [$daysAgo, $localScore, $guestScore]) {
        // Away at a rival: 0-2 is a win for the player's team, 3-1 a loss.
        Fixture::factory()->create([
            'season_id' => $this->season->id, 'week_number' => 1, 'date' => now()->subDays($daysAgo),
            'team_local_id' => Team::factory()->create()->id, 'team_guest_id' => $player->team_id,
            'local_score' => $localScore, 'guest_score' => $guestScore, 'state' => FixtureState::Finished,
        ]);
    }

    $inputs = app(MaxBidCalculator::class)->gatherInputs($player, $this->season);

    expect($inputs->recentTeamPoints)->toBe([0, 3, 1])
        ->and($inputs->recentParticipation)->toHaveCount(3);
});

test('without market rows for the reference day yet, the estimate uses the latest published day for both the player and the market', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    // The next morning, before that day's market values are published.
    $this->travelTo('2026-09-27 08:00:00');

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->referenceDate)->toBe('2026-09-26')
        ->and($estimate->toArray()['reference_date'])->toBe('2026-09-26')
        ->and($estimate->value)->toBe(10_300_000)
        ->and($estimate->momentumIncrement)->toEqualWithDelta(100_000, 0.01)
        // The player's own rise moved the market index over 23/09–26/09.
        ->and($estimate->marketAdjustment)->toBeLessThan(0.0);
});

test('the reference date is the latest published market day up to the requested date', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    expect(app(MaxBidCalculator::class)->estimate($player, $this->season)->referenceDate)->toBe('2026-09-26')
        ->and(app(MaxBidCalculator::class)->estimate($player, $this->season, now()->subDay())->referenceDate)->toBe('2026-09-25');
});

test('gathering the inputs flags a strong rise from the last seven values up to the reference date', function (array $values, bool $expected): void {
    $player = maxBidPlayer($this->season, $values);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->strongRise)->toBe($expected);
})->with([
    'steady rise' => [[10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000, 10_600_000], true],
    'accelerating rise' => [[10_000_000, 10_050_000, 10_100_000, 10_150_000, 10_400_000, 10_650_000, 10_900_000], true],
    'decelerating rise' => [[10_000_000, 10_300_000, 10_600_000, 10_900_000, 10_950_000, 11_000_000, 11_050_000], false],
    'fall' => [[10_600_000, 10_500_000, 10_400_000, 10_300_000, 10_200_000, 10_100_000, 10_000_000], false],
    'too little history for a trend' => [[10_000_000, 10_100_000, 10_200_000, 10_300_000], false],
]);

test('an unplayed match later today is an upcoming rival 0 days away, making it a matchweek', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    $rival = Team::factory()->create();
    Fixture::factory()->create([
        'season_id' => $this->season->id, 'week_number' => 8, 'date' => now()->setTime(21, 0),
        'team_local_id' => $player->team_id, 'team_guest_id' => $rival->id, 'state' => FixtureState::Scheduled,
    ]);

    $inputs = app(MaxBidCalculator::class)->gatherInputs($player, $this->season);

    expect($inputs->upcomingRivals)->toHaveCount(1)
        ->and($inputs->upcomingRivals[0]['team']->id)->toBe($rival->id)
        ->and($inputs->upcomingRivals[0]['days_until'])->toBe(0)
        ->and($inputs->isBreak())->toBeFalse();
});

test('a match already finished earlier today or a postponed one is not an upcoming rival', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    Fixture::factory()->create([
        'season_id' => $this->season->id, 'week_number' => 7, 'date' => now()->setTime(10, 0),
        'team_local_id' => $player->team_id, 'team_guest_id' => Team::factory()->create()->id,
        'local_score' => 1, 'guest_score' => 0, 'state' => FixtureState::Finished,
    ]);
    Fixture::factory()->create([
        'season_id' => $this->season->id, 'week_number' => 8, 'date' => now()->addDays(3)->setTime(18, 0),
        'team_local_id' => $player->team_id, 'team_guest_id' => Team::factory()->create()->id, 'state' => FixtureState::Postponed,
    ]);

    $inputs = app(MaxBidCalculator::class)->gatherInputs($player, $this->season);

    expect($inputs->upcomingRivals)->toBe([])
        ->and($inputs->isBreak())->toBeTrue();
});

test('gatherInputs takes the stored forecast made on its own reference date only', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    ValueForecast::factory()->create([
        'season_id' => $this->season->id,
        'player_id' => $player->id,
        'reference_date' => '2026-09-25',
        'target_date' => '2026-09-26',
        'predicted_value' => 1,
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->dayOneForecast)->toBeNull();

    ValueForecast::factory()->create([
        'season_id' => $this->season->id,
        'player_id' => $player->id,
        'reference_date' => '2026-09-26',
        'target_date' => '2026-09-27',
        'predicted_value' => 10_420_000,
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->dayOneForecast)->toBe(10_420_000)
        ->and(app(MaxBidCalculator::class)->estimate($player, $this->season)->projection[1])->toBe(10_420_000);
});

test('an unavailable player never reads the forecast', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => PlayerStatus::Injured]);
    ValueForecast::factory()->create([
        'season_id' => $this->season->id,
        'player_id' => $player->id,
        'reference_date' => '2026-09-26',
        'target_date' => '2026-09-27',
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season)->dayOneForecast)->toBeNull();
});

test('gatherInputs can leave the stored forecast unread', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);
    ValueForecast::factory()->create([
        'season_id' => $this->season->id,
        'player_id' => $player->id,
        'reference_date' => '2026-09-26',
        'target_date' => '2026-09-27',
    ]);

    expect(app(MaxBidCalculator::class)->gatherInputs($player, $this->season, readStoredForecast: false)->dayOneForecast)->toBeNull();
});
