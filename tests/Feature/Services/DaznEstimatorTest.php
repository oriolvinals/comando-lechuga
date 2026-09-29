<?php

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Services\DaznEstimator;

/**
 * An unsaved lineup + fixture pair for the estimator, which is pure and never queries.
 *
 * @param  array<string, mixed>  $lineup
 * @param  array<string, mixed>  $fixture
 * @return array{0: FixtureLineup, 1: Fixture}
 */
function daznCase(array $lineup = [], array $fixture = []): array
{
    $fixtureModel = new Fixture([
        'team_local_id' => 1,
        'team_guest_id' => 2,
        'local_score' => 0,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
        'display_clock' => null,
        ...$fixture,
    ]);

    $lineupModel = new FixtureLineup([
        'team_id' => 1,
        'starter' => true,
        'subbed_in' => false,
        'subbed_out' => false,
        'sub_minute' => null,
        'stats' => [],
        'fantasy_stats' => null,
        ...$lineup,
    ]);

    return [$lineupModel, $fixtureModel];
}

test('reproduces the blind-tested v1 ratings on real J1–J7 performances', function (array $row): void {
    $position = PlayerPosition::from($row['position']);
    $fixture = ['local_score' => $row['team_goals'], 'guest_score' => $row['rival_goals']];
    $lineup = [
        'starter' => $row['starter'],
        'subbed_in' => $row['subbed_in'],
        'subbed_out' => $row['subbed_out'],
        'sub_minute' => $row['sub_minute'],
        'stats' => $row['stats'],
    ];
    $estimator = new DaznEstimator;

    [$withFantasy, $fixtureModel] = daznCase([...$lineup, 'fantasy_stats' => $row['fantasy_stats']], $fixture);
    [$worldcup26Only] = daznCase($lineup, $fixture);

    expect($estimator->estimate($withFantasy, $fixtureModel, $position)?->points)->toBe($row['expected_fantasy'])
        ->and($estimator->estimate($worldcup26Only, $fixtureModel, $position)?->points)->toBe($row['expected_worldcup26']);
})->with(fn (): array => array_map(
    fn (array $row): array => [$row],
    json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/dazn/estimate-v1-golden.json'), true),
));

test('uses Fantasy stats when present and worldcup26 as the fallback', function (): void {
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [90, 2]]]);
    [$fallback] = daznCase();

    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Midfield)?->source)->toBe('fantasy')
        ->and((new DaznEstimator)->estimate($fallback, $fixture, PlayerPosition::Midfield)?->source)->toBe('worldcup26');
});

test('returns no estimate for a coach, an unknown position, or zero minutes', function (): void {
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [90, 2]]]);
    [$unusedSub] = daznCase(['starter' => false, 'fantasy_stats' => ['mins_played' => [0, 0]]]);
    [$unusedSubFallback] = daznCase(['starter' => false]);

    $estimator = new DaznEstimator;

    expect($estimator->estimate($lineup, $fixture, PlayerPosition::Coach))->toBeNull()
        ->and($estimator->estimate($lineup, $fixture, null))->toBeNull()
        ->and($estimator->estimate($unusedSub, $fixture, PlayerPosition::Defender))->toBeNull()
        ->and($estimator->estimate($unusedSubFallback, $fixture, PlayerPosition::Defender))->toBeNull();
});

test('derives worldcup26 minutes from the lineup and the match clock', function (array $lineup, array $fixture, int $minutes): void {
    [$lineupModel, $fixtureModel] = daznCase($lineup, $fixture);

    expect((new DaznEstimator)->estimate($lineupModel, $fixtureModel, PlayerPosition::Midfield)?->minutes)->toBe($minutes);
})->with([
    'starter, finished' => [[], ['state' => FixtureState::Finished], 90],
    'starter, half time' => [[], ['state' => FixtureState::HalfTime], 45],
    'starter, second half with stoppage' => [[], ['state' => FixtureState::SecondHalf, 'display_clock' => "67'"], 67],
    'starter, first-half stoppage' => [[], ['state' => FixtureState::FirstHalf, 'display_clock' => "45'+2'"], 45],
    'starter subbed out' => [['subbed_out' => true, 'sub_minute' => 58], ['state' => FixtureState::Finished], 58],
    'sub came on' => [['starter' => false, 'subbed_in' => true, 'sub_minute' => 70], ['state' => FixtureState::SecondHalf, 'display_clock' => "81'"], 11],
]);

test('treats a null score as 0-0 and missing or non-numeric stats as 0', function (): void {
    [$lineup, $fixture] = daznCase(
        ['stats' => [['name' => 'totalGoals', 'value' => 'n/a'], ['value' => 3], ['name' => 'foulsCommitted']]],
        ['local_score' => null, 'guest_score' => null, 'state' => FixtureState::Finished],
    );

    // Defender, 90', worldcup26 only: base 0,80 + 1,25 + 0,10 (≥30') + clean sheet 0,20 = 2,35 → 3.
    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Defender)?->points)->toBe(3);
});

test('a raw value equal to a threshold counts in the upper band', function (): void {
    // Midfielder, 90', Fantasy: base 0,80 + 0,50 + 1 shot × 0,20 = 1,50 exactly → 2.
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [90, 2], 'total_scoring_att' => [1, 0]]]);

    $estimate = (new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Midfield);

    expect($estimate?->raw)->toBe(1.5)
        ->and($estimate?->points)->toBe(2);
});

test('explains the estimate with minutes first and up to three actions by impact', function (): void {
    [$lineup, $fixture] = daznCase(
        ['fantasy_stats' => ['mins_played' => [90, 2], 'goals' => [1, 5], 'total_scoring_att' => [3, 0], 'yellow_card' => [1, -1], 'ball_recovery' => [2, 0]], 'stats' => [['name' => 'foulsCommitted', 'value' => 2]]],
        ['local_score' => 1, 'guest_score' => 0],
    );

    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Striker)?->reasons)
        ->toBe(['90 minutos jugados', '1 gol', '3 tiros', '1 amarilla']);
});

test('says there were no goals, assists nor cards when no action counts', function (): void {
    [$lineup, $fixture] = daznCase(['fantasy_stats' => ['mins_played' => [20, 1]]], ['local_score' => 0, 'guest_score' => 1]);

    expect((new DaznEstimator)->estimate($lineup, $fixture, PlayerPosition::Striker)?->reasons)
        ->toBe(['20 minutos jugados', 'Sin goles, asistencias ni tarjetas']);
});
