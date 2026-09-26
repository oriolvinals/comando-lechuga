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
    $estimate = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters);

    expect($estimate->status)->toBe(MaxBidStatus::Profitable)
        ->and($estimate->dailyIncrement)->toEqual(100_000.0)
        ->and($estimate->projection)->toBe(MaxBidCalculator::project(10_000_000, 100_000.0))
        ->and($estimate->confidence)->toBe(MaxBidCalculator::CONFIDENCE);
});

test('a different decay changes the projection', function (): void {
    $default = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters);
    $slower = MaxBidCalculator::estimateFromInputs(formulaInputs(), new MaxBidParameters(incrementDecayBreak: 0.8));

    expect($slower->projection[14])->toBeLessThan($default->projection[14])
        ->and($slower->bid)->toBeLessThan($default->bid);
});

test('the decay follows the phase: break beyond 7 days to the next match, matchweek within', function (?int $daysToNextMatch, float $expectedDecay): void {
    $inputs = formulaInputs(['upcomingRivals' => $daysToNextMatch === null ? [] : [formulaRival($daysToNextMatch)]]);
    $parameters = new MaxBidParameters(incrementDecayBreak: 0.5, incrementDecayMatchweek: 0.95);

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

    $estimate = MaxBidCalculator::estimateFromInputs($benched, new MaxBidParameters(benchIncrementFactor: 0.25));

    expect($estimate->participation)->toBeLessThan(0.4)
        ->and($estimate->dailyIncrement)->toEqualWithDelta(25_000.0, 0.0001);
});

test('benchesBeforeUnprofitable makes a rising player unprofitable after that many 0-minute matches', function (int $benches, array $minutes, MaxBidStatus $expected): void {
    $inputs = formulaInputs(['recentParticipation' => array_map(
        fn (int $played): array => ['starter' => $played > 0, 'minutes' => $played],
        $minutes,
    )]);

    $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters(benchesBeforeUnprofitable: $benches));

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

    $estimate = MaxBidCalculator::estimateFromInputs($inputs, new MaxBidParameters(badScoreRule: $rule));

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
    $estimate = MaxBidCalculator::estimateFromInputs(new MaxBidInputs(value: 5_000_000, presetStatus: $status), new MaxBidParameters, 0.9);

    expect($estimate->status)->toBe($status)
        ->and($estimate->value)->toBe(5_000_000)
        ->and($estimate->confidence)->toBe(0.9)
        ->and($estimate->projection)->toBeNull();
})->with([MaxBidStatus::Unavailable, MaxBidStatus::NoData]);
