<?php

use App\Enums\BadScoreRule;
use App\Enums\MaxBidStatus;
use App\Models\Team;
use App\Services\MaxBidCalculator;
use App\Services\MaxBidInputs;
use App\Services\MaxBidParameters;

/**
 * Inputs of a rising, regular starter with a neutral sport score (no upcoming
 * fixture), so the daily increment is his momentum alone.
 *
 * @param  array<string, mixed>  $overrides
 */
function formulaInputs(array $overrides = []): MaxBidInputs
{
    return new MaxBidInputs(...[
        'value' => 10_000_000,
        'momentum' => 100_000.0,
        'marketPace' => 0.0,
        'lastPoints' => [5, 5, 5],
        'seasonPointsAverage' => 5.0,
        'recentParticipation' => array_fill(0, 3, ['starter' => true, 'minutes' => 90]),
        'upcomingRivals' => [],
        'teamCount' => 20,
        'doubtful' => false,
        ...$overrides,
    ]);
}

/**
 * @return array{team: Team, position: int, days_until: int, difficulty: float}
 */
function formulaRival(int $daysUntil): array
{
    return ['team' => new Team, 'position' => 10, 'days_until' => $daysUntil, 'difficulty' => 0.0];
}

test('the defaults reproduce a plain rising player', function (): void {
    $estimate = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0));

    expect($estimate->status)->toBe(MaxBidStatus::Profitable)
        ->and($estimate->dailyIncrement)->toEqual(100_000.0)
        ->and($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, 100_000.0, (new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0))->incrementDecayBreak))
        ->and($estimate->confidence)->toBe(MaxBidCalculator::CONFIDENCE);
});

test('a different decay changes the projection', function (): void {
    $default = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, incrementDecayBreak: 0.9));
    $slower = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, incrementDecayBreak: 0.8));

    expect($slower->projection[14])->toBeLessThan($default->projection[14])
        ->and($slower->bid)->toBeLessThan($default->bid);
});

test('the decay follows the phase: break beyond 7 days to the next match, matchweek within', function (?int $daysToNextMatch, float $expectedDecay): void {
    $inputs = formulaInputs(['upcomingRivals' => $daysToNextMatch === null ? [] : [formulaRival($daysToNextMatch)]]);
    $parameters = new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, incrementDecayBreak: 0.5, incrementDecayMatchweek: 0.95);

    $estimate = MaxBidCalculator::estimateFromInputs($inputs, $parameters);

    expect($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, $estimate->dailyIncrement, $expectedDecay));
})->with([
    'no upcoming match' => [null, 0.5],
    '8 days away' => [8, 0.5],
    '7 days away' => [7, 0.95],
    'tomorrow' => [1, 0.95],
]);

test('a bench player gets the bench factor of a positive increment', function (): void {
    $benched = formulaInputs(['recentParticipation' => array_fill(0, 3, ['starter' => false, 'minutes' => 10])]);

    $estimate = MaxBidCalculator::estimateFromInputs($benched, new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, benchIncrementFactor: 0.25));

    expect($estimate->participation)->toBeLessThan(0.4)
        ->and($estimate->dailyIncrement)->toEqualWithDelta(25_000.0, 0.0001);
});

test('benchesBeforeUnprofitable makes a rising player unprofitable after that many 0-minute matches', function (int $benches, array $minutes, MaxBidStatus $expected): void {
    $inputs = formulaInputs(['recentParticipation' => array_map(
        fn (int $played): array => ['starter' => $played > 0, 'minutes' => $played],
        $minutes,
    )]);

    $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, benchesBeforeUnprofitable: $benches));

    expect($estimate->status)->toBe($expected);
})->with([
    'never, benched last match' => [0, [0, 90, 90], MaxBidStatus::Profitable],
    'one, benched last match' => [1, [0, 90, 90], MaxBidStatus::Unprofitable],
    'one, played last match' => [1, [90, 0, 0], MaxBidStatus::Profitable],
    'two, benched only last match' => [2, [0, 90, 90], MaxBidStatus::Profitable],
    'two, benched last two matches' => [2, [0, 0, 90], MaxBidStatus::Unprofitable],
    'two, the team has played only once' => [2, [0], MaxBidStatus::Profitable],
]);

test('each bad score rule triggers at its boundary and not past it', function (BadScoreRule $rule, array $lastPoints, float $seasonAverage, MaxBidStatus $expected): void {
    $inputs = formulaInputs(['lastPoints' => $lastPoints, 'seasonPointsAverage' => $seasonAverage]);

    $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, badScoreRule: $rule));

    expect($estimate->status)->toBe($expected);
})->with([
    'off, 0 points' => [BadScoreRule::Off, [0, 5, 5], 5.0, MaxBidStatus::Profitable],
    '≤1, 1 point' => [BadScoreRule::AtMostOne, [1, 5, 5], 5.0, MaxBidStatus::Unprofitable],
    '≤1, 2 points' => [BadScoreRule::AtMostOne, [2, 5, 5], 5.0, MaxBidStatus::Profitable],
    '≤2, 2 points' => [BadScoreRule::AtMostTwo, [2, 5, 5], 5.0, MaxBidStatus::Unprofitable],
    '≤2, 3 points' => [BadScoreRule::AtMostTwo, [3, 5, 5], 5.0, MaxBidStatus::Profitable],
    '<40 %, 3 of 10' => [BadScoreRule::BelowFortyPercentOfAverage, [3, 10, 10], 10.0, MaxBidStatus::Unprofitable],
    '<40 %, 4 of 10' => [BadScoreRule::BelowFortyPercentOfAverage, [4, 10, 10], 10.0, MaxBidStatus::Profitable],
    '≤2, never played' => [BadScoreRule::AtMostTwo, [], 0.0, MaxBidStatus::Profitable],
]);

test('a status-only input is returned as that status, with no factors', function (MaxBidStatus $status): void {
    $estimate = MaxBidCalculator::estimateFromInputs(new MaxBidInputs(value: 5_000_000, presetStatus: $status), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0), 0.9);

    expect($estimate->status)->toBe($status)
        ->and($estimate->value)->toBe(5_000_000)
        ->and($estimate->confidence)->toBe(0.9)
        ->and($estimate->projection)->toBeNull();
})->with([MaxBidStatus::Unavailable, MaxBidStatus::NoData]);

test('benchesBeforeUnprofitable can only count the three matches that are gathered', function (int $benches): void {
    expect(fn (): MaxBidParameters => new MaxBidParameters(benchesBeforeUnprofitable: $benches))
        ->toThrow(InvalidArgumentException::class);
})->with([-1, 4]);

/**
 * A 2-point last score (bad under the ≤ 2 rule) on a strong streak: 6 %/day,
 * the team won all of its last three matches and he started all three.
 *
 * @param  array<string, mixed>  $overrides
 */
function streakInputs(array $overrides = []): MaxBidInputs
{
    return formulaInputs([
        'momentum' => 600_000.0,
        'lastPoints' => [2, 8, 8],
        'seasonPointsAverage' => 6.0,
        'recentTeamPoints' => [3, 3, 3],
        ...$overrides,
    ]);
}

test('the streak exception lifts the bad-score cap only when all three conditions hold', function (array $overrides, MaxBidStatus $expected): void {
    $parameters = new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, badScoreRule: BadScoreRule::AtMostTwo, streakExceptionPace: 0.05, streakExceptionTeamPoints: 6);

    expect(MaxBidCalculator::estimateFromInputs(streakInputs($overrides), $parameters)->status)->toBe($expected);
})->with([
    'all three hold' => [[], MaxBidStatus::Profitable],
    'pace below the threshold' => [['momentum' => 400_000.0], MaxBidStatus::Unprofitable],
    'team points below the threshold' => [['recentTeamPoints' => [3, 1, 1]], MaxBidStatus::Unprofitable],
    'did not start one of the three' => [['recentParticipation' => [
        ['starter' => true, 'minutes' => 90], ['starter' => false, 'minutes' => 60], ['starter' => true, 'minutes' => 90],
    ]], MaxBidStatus::Unprofitable],
    'the team has played only two matches' => [[
        'recentParticipation' => array_fill(0, 2, ['starter' => true, 'minutes' => 90]),
        'recentTeamPoints' => [3, 3],
    ], MaxBidStatus::Unprofitable],
]);

test('the streak exception is off when its pace is null', function (): void {
    $estimate = MaxBidCalculator::estimateFromInputs(streakInputs(), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, badScoreRule: BadScoreRule::AtMostTwo));

    expect((new MaxBidParameters)->streakExceptionPace)->toBeNull()
        ->and($estimate->status)->toBe(MaxBidStatus::Unprofitable)
        ->and($estimate->dailyIncrement)->toBe(0.0);
});

test('the streak exception never lifts the bench rules', function (): void {
    // On a streak, but 0 minutes in the last match (a listed starter who didn't play).
    $inputs = streakInputs(['lastPoints' => [8, 8, 8], 'recentParticipation' => [
        ['starter' => true, 'minutes' => 0], ['starter' => true, 'minutes' => 90], ['starter' => true, 'minutes' => 90],
    ]]);
    $parameters = new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, benchesBeforeUnprofitable: 1, streakExceptionPace: 0.05, streakExceptionTeamPoints: 6);

    expect(MaxBidCalculator::estimateFromInputs($inputs, $parameters)->status)->toBe(MaxBidStatus::Unprofitable);
});

test('a strong riser keeps his momentum longer in a break', function (bool $strongRise, float $momentum, ?int $daysToNextMatch, float $expectedDecay): void {
    $inputs = formulaInputs([
        'strongRise' => $strongRise,
        'momentum' => $momentum,
        'upcomingRivals' => $daysToNextMatch === null ? [] : [formulaRival($daysToNextMatch)],
    ]);

    $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0));

    expect($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, $estimate->dailyIncrement, $expectedDecay));
})->with([
    'strong riser, break' => [true, 100_000.0, null, 0.925],
    'strong riser, 8 days to the next match' => [true, 100_000.0, 8, 0.925],
    'strong riser, matchweek' => [true, 100_000.0, 3, 0.9],
    'decelerating riser, break' => [false, 100_000.0, null, 0.9],
    'non-riser, break' => [false, -100_000.0, null, 0.9],
]);

test('without a start probability the participation and sport score are the recent ones, as before', function (): void {
    $inputs = formulaInputs([
        'recentParticipation' => [['starter' => false, 'minutes' => 30], ['starter' => true, 'minutes' => 90], ['starter' => true, 'minutes' => 60]],
        'upcomingRivals' => [formulaRival(2)],
    ]);

    $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0));

    // p = 0,5·(0 + 0,5·30/90) + 0,3·1 + 0,2·(0,5 + 0,5·60/90); form 0 and a neutral rival,
    // so score = 0,5^(2/7)·0,3·(2p − 1), exactly the formula before the start probability.
    $participation = 0.5 * (0.5 * 30 / 90) + 0.3 + 0.2 * (0.5 + 0.5 * 60 / 90);

    expect($inputs->nextStartProbability)->toBeNull()
        ->and($estimate->participation)->toEqualWithDelta($participation, 1e-12)
        ->and($estimate->sportScore)->toEqualWithDelta(0.5 ** (2 / 7) * 0.3 * (2 * $participation - 1), 1e-12);
});

test('a start probability pulls the participation towards it by startProbabilityWeight', function (float $probability, float $weight): void {
    $recent = [['starter' => false, 'minutes' => 30], ['starter' => true, 'minutes' => 90], ['starter' => true, 'minutes' => 60]];
    $without = MaxBidCalculator::estimateFromInputs(formulaInputs(['recentParticipation' => $recent]), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, startProbabilityWeight: $weight));

    $estimate = MaxBidCalculator::estimateFromInputs(
        formulaInputs(['recentParticipation' => $recent, 'nextStartProbability' => $probability]),
        new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, startProbabilityWeight: $weight),
    );

    expect($estimate->participation)->toEqualWithDelta((1 - $weight) * $without->participation + $weight * $probability, 1e-12)
        ->and($probability === 1.0 ? $estimate->participation > $without->participation : $estimate->participation < $without->participation)->toBeTrue();
})->with([
    'sure starter, default weight' => [1.0, 0.5],
    'sure bench, default weight' => [0.0, 0.5],
    'sure starter, light weight' => [1.0, 0.25],
]);

test('the start probability weight defaults to 0,5', function (): void {
    expect((new MaxBidParameters)->startProbabilityWeight)->toBe(0.5);
});

test('the estimate exposes recent participation, the start probability and its weight next to the mixed participation', function (?float $probability): void {
    $recent = [['starter' => false, 'minutes' => 30], ['starter' => true, 'minutes' => 90], ['starter' => true, 'minutes' => 60]];
    $parameters = new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0, startProbabilityWeight: 0.4);
    $without = MaxBidCalculator::estimateFromInputs(formulaInputs(['recentParticipation' => $recent]), $parameters);

    $estimate = MaxBidCalculator::estimateFromInputs(formulaInputs(['recentParticipation' => $recent, 'nextStartProbability' => $probability]), $parameters);
    $array = $estimate->toArray();

    expect($estimate->recentParticipationShare)->toEqualWithDelta($without->participation, 1e-12)
        ->and($estimate->nextStartProbability)->toBe($probability)
        ->and($estimate->startProbabilityWeight)->toBe(0.4)
        ->and($array['recent_participation_share'])->toBe($estimate->recentParticipationShare)
        ->and($array['next_start_probability'])->toBe($probability)
        ->and($array['start_probability_weight'])->toBe(0.4);
})->with([
    'without probability' => [null],
    'with probability' => [0.9],
]);

test('the value forecast becomes day 1 and shifts days 2–14 by the same amount', function (): void {
    $without = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0));
    $with = MaxBidCalculator::estimateFromInputs(formulaInputs(['dayOneForecast' => 10_150_000]), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0));
    $offset = 10_150_000 - $without->projection[1];

    expect($with->projection[0])->toBe(10_000_000)
        ->and($with->projection[1])->toBe(10_150_000)
        ->and(array_map(fn (int $before, int $after): int => $after - $before, array_slice($without->projection, 1), array_slice($with->projection, 1)))
        ->toBe(array_fill(0, MaxBidCalculator::LOCK_DAYS, $offset))
        ->and($with->dayOneForecast)->toBe(10_150_000)
        ->and($with->dayOneOffset)->toBe($offset)
        ->and($with->toArray()['day_one_forecast'])->toBe(10_150_000)
        ->and($with->status)->toBe($without->status)
        ->and($with->dailyIncrement)->toBe($without->dailyIncrement)
        ->and($with->bid)->toBeGreaterThan($without->bid);
});

test('a forecast below today moves the path but never the profitability', function (): void {
    $with = MaxBidCalculator::estimateFromInputs(formulaInputs(['dayOneForecast' => 9_700_000]), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0));

    expect($with->status)->toBe(MaxBidStatus::Profitable)
        ->and($with->projection[1])->toBe(9_700_000);
});

test('without a forecast the projection is the plain one', function (): void {
    $estimate = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0));

    expect($estimate->dayOneForecast)->toBeNull()
        ->and($estimate->dayOneOffset)->toBeNull()
        ->and($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, 100_000.0, (new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0))->incrementDecayBreak));
});

test('withDayOneForecast keeps every other input', function (): void {
    $inputs = formulaInputs(['doubtful' => true, 'referenceDate' => '2026-09-26']);
    $copy = $inputs->withDayOneForecast(10_050_000);

    expect($copy->dayOneForecast)->toBe(10_050_000)
        ->and($copy->doubtful)->toBeTrue()
        ->and($copy->referenceDate)->toBe('2026-09-26')
        ->and($copy->value)->toBe($inputs->value)
        ->and($inputs->dayOneForecast)->toBeNull();
});

test('a shrink scales the projected increments but not the reported factors nor the profitability', function (): void {
    $parameters = new MaxBidParameters(confidenceCalibration: [], incrementShrink: 0.5);
    $estimate = MaxBidCalculator::estimateFromInputs(formulaInputs(), $parameters);

    expect($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, 50_000.0, $parameters->incrementDecayBreak))
        ->and($estimate->dailyIncrement)->toEqual(100_000.0)
        ->and($estimate->status)->toBe(MaxBidStatus::Profitable);
});

test('the bid is solved at the calibrated confidence while the estimate keeps the chosen one', function (): void {
    $calibrated = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: calibrationKnots(0.15), incrementShrink: 1.0), 0.75);
    $plainAt90 = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(confidenceCalibration: [], incrementShrink: 1.0), 0.9);

    expect($calibrated->confidence)->toBe(0.75)
        ->and($calibrated->bid)->toBe($plainAt90->bid);
});
