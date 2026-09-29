<?php

use App\Enums\DifficultyVariant;
use App\Enums\PlayerPosition;
use App\Services\TeamStrength;
use App\Services\TeamStrengthInputs;
use App\Services\TeamStrengthParameters;

/**
 * @param  array<string, mixed>  $row  One row of tests/Fixtures/team-strength-parity.json
 */
function parityInputs(int $teamId, array $row): TeamStrengthInputs
{
    return new TeamStrengthInputs(
        teamId: $teamId,
        matches: $row['matches'],
        logValue: $row['log_value'],
        goalDifference: $row['goal_difference'] ?? null,
        shotsOnTargetDifference: $row['shots_on_target_difference'] ?? null,
        keyPassesFor: $row['key_passes_for'] ?? null,
        goalsFor: $row['goals_for'] ?? null,
        shotsOnTargetFor: $row['shots_on_target_for'] ?? null,
        failedToScoreRate: $row['failed_to_score_rate'] ?? null,
        goalsAgainst: $row['goals_against'] ?? null,
        shotsOnTargetAgainst: $row['shots_on_target_against'] ?? null,
        keyPassesAgainst: $row['key_passes_against'] ?? null,
    );
}

test('reproduces the study strengths after J7', function (): void {
    $rows = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/team-strength-parity.json'), true);
    $inputs = array_map(fn (int $i): TeamStrengthInputs => parityInputs($i + 1, $rows[$i]), array_keys($rows));

    $ratings = TeamStrength::fromInputs($inputs, new TeamStrengthParameters);

    foreach ($rows as $i => $row) {
        $rating = $ratings[$i + 1];
        expect($rating->general)->toEqualWithDelta($row['expected']['general'], 1e-4)
            ->and($rating->offensiveThreat)->toEqualWithDelta($row['expected']['offensive_threat'], 1e-4)
            ->and($rating->defensiveSolidity)->toEqualWithDelta($row['expected']['defensive_solidity'], 1e-4);
    }
});

test('a team with no matches is rated on squad value only', function (): void {
    $ratings = TeamStrength::fromInputs([
        new TeamStrengthInputs(1, 0, log(300e6), null, null, null, null, null, null, null, null, null),
        new TeamStrengthInputs(2, 0, log(100e6), null, null, null, null, null, null, null, null, null),
    ], new TeamStrengthParameters);

    expect($ratings[1]->general)->toEqualWithDelta(1.0, 1e-9)
        ->and($ratings[2]->general)->toEqualWithDelta(-1.0, 1e-9)
        ->and($ratings[1]->offensiveThreat)->toEqualWithDelta(1.0, 1e-9);
});

test('identical teams all rate 0 instead of dividing by zero', function (): void {
    $same = fn (int $id): TeamStrengthInputs => new TeamStrengthInputs($id, 3, 18.0, 0.5, 1.0, 4.0, 1.5, 4.0, 0.2, 1.0, 3.0, 3.0);

    $ratings = TeamStrength::fromInputs([$same(1), $same(2), $same(3)], new TeamStrengthParameters);

    expect(array_map(fn ($r): float => $r->general, $ratings))->toBe([1 => 0.0, 2 => 0.0, 3 => 0.0]);
});

test('the performance weight grows with matches played', function (): void {
    // Team 1 is the priciest but performs worst; with more matches it sinks toward its performance.
    $make = fn (int $matches): array => [
        new TeamStrengthInputs(1, $matches, 20.0, -1.0, -3.0, 2.0, 0.8, 2.0, 0.5, 1.8, 5.0, 6.0),
        new TeamStrengthInputs(2, $matches, 19.0, 0.0, 0.0, 4.0, 1.3, 4.0, 0.2, 1.3, 4.0, 4.0),
        new TeamStrengthInputs(3, $matches, 18.0, 1.0, 3.0, 6.0, 1.8, 6.0, 0.1, 0.8, 3.0, 2.0),
    ];

    $early = TeamStrength::fromInputs($make(2), new TeamStrengthParameters)[1]->general;
    $late = TeamStrength::fromInputs($make(24), new TeamStrengthParameters)[1]->general;

    expect($early)->toBeGreaterThan($late);
});

test('maps positions to difficulty variants', function (?PlayerPosition $position, DifficultyVariant $variant): void {
    expect(DifficultyVariant::forPosition($position))->toBe($variant);
})->with([
    [PlayerPosition::Goalkeeper, DifficultyVariant::Defense],
    [PlayerPosition::Defender, DifficultyVariant::Defense],
    [PlayerPosition::Midfield, DifficultyVariant::Attack],
    [PlayerPosition::Striker, DifficultyVariant::Attack],
    [PlayerPosition::Coach, DifficultyVariant::General],
    [null, DifficultyVariant::General],
]);
