<?php

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\ValueForecast\ValueForecastFeatures;
use App\Services\ValueForecast\ValueForecastFeatureVector;
use App\Services\ValueForecast\ValueForecastParameters;
use App\Services\ValueForecast\ValueForecastRow;

beforeEach(function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
});

/**
 * @param  list<ValueForecastRow>  $rows
 */
function onlyRowOf(array $rows, Player $player): ValueForecastRow
{
    $mine = array_values(array_filter($rows, fn (ValueForecastRow $row): bool => $row->playerId === $player->id));
    expect($mine)->toHaveCount(1);

    return $mine[0];
}

test('builds a row per player-day with its changes, the market mean and the next value', function (): void {
    $riser = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_500_000], '2026-09-24');
    $flat = forecastPlayer($this->season, array_fill(0, 5, 5_000_000), '2026-09-24');

    $rows = app(ValueForecastFeatures::class)->rows($this->season, '2026-09-23');
    $row = onlyRowOf($rows, $riser);

    expect($rows)->toHaveCount(2)
        ->and($row->referenceDate)->toBe('2026-09-23')
        ->and($row->targetDate)->toBe('2026-09-24')
        ->and($row->value)->toBe(10_300_000)
        ->and($row->changeToday)->toEqualWithDelta(100_000 / 10_200_000, 1e-12)
        ->and($row->changeYesterday)->toEqualWithDelta(100_000 / 10_100_000, 1e-12)
        ->and($row->changeBefore)->toEqualWithDelta(100_000 / 10_000_000, 1e-12)
        ->and($row->marketChange)->toEqualWithDelta((100_000 / 10_200_000) / 2, 1e-12)
        ->and($row->nextValue)->toBe(10_500_000)
        ->and($row->matchYesterday)->toBe(['team' => false, 'played' => false, 'points' => 0])
        ->and($row->daysToNextMatch)->toBe(30)
        ->and(onlyRowOf($rows, $flat)->changeToday)->toBe(0.0);
});

test('reads yesterday, today and the day before\'s matches, the next match and the average with minutes', function (): void {
    $player = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], '2026-09-23');
    $rival = Team::factory()->create();
    $match = fn (string $date, FixtureState $state = FixtureState::Finished): Fixture => Fixture::factory()->create([
        'season_id' => $this->season->id,
        'team_local_id' => $player->team_id,
        'team_guest_id' => $rival->id,
        'date' => "{$date} 18:00:00",
        'state' => $state,
    ]);
    $lineup = fn (Fixture $fixture, ?int $points, int $minutes): FixtureLineup => FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $player->team_id,
        'fantasy_points' => $points,
        'fantasy_stats' => ['mins_played' => [$minutes, 2]],
    ]);
    $lineup($match('2026-09-15'), 8, 0);      // no minutes: left out of the average
    $lineup($match('2026-09-21'), null, 30);  // pending points count as 0
    $lineup($match('2026-09-22'), 12, 90);
    $match('2026-09-23');                     // his team played, he has no lineup
    $match('2026-09-27', FixtureState::Scheduled);

    $row = onlyRowOf(app(ValueForecastFeatures::class)->rows($this->season, '2026-09-23'), $player);

    expect($row->matchYesterday)->toBe(['team' => true, 'played' => true, 'points' => 12])
        ->and($row->matchToday)->toBe(['team' => true, 'played' => false, 'points' => 0])
        ->and($row->matchBefore)->toBe(['team' => true, 'played' => true, 'points' => 0])
        ->and($row->daysToNextMatch)->toBe(4)
        ->and($row->averagePoints)->toBe(6.0)
        ->and($row->nextValue)->toBeNull();
});

test('skips a player-day with a missing or zero value around it and leaves an unusable next value unknown', function (): void {
    $gap = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], '2026-09-23');
    PlayerMarket::query()->where('player_id', $gap->id)->where('date', '2026-09-21')->delete();
    forecastPlayer($this->season, [10_000_000, 0, 10_200_000, 10_300_000], '2026-09-23');
    $zeroNext = forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 0], '2026-09-24');

    $rows = app(ValueForecastFeatures::class)->rows($this->season, '2026-09-23');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->playerId)->toBe($zeroNext->id)
        ->and($rows[0]->nextValue)->toBeNull();
});

test('starts 13 days after the season start', function (): void {
    $this->season->update(['start_date' => '2026-09-15']);
    forecastPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000, 10_500_000], '2026-09-28');

    $rows = app(ValueForecastFeatures::class)->rows($this->season->fresh(), '2026-09-28');

    expect(array_map(fn (ValueForecastRow $row): string => $row->referenceDate, $rows))->toBe(['2026-09-28']);
});

test('reproduces the research rows, variables and targets of backtest.js within 1e-12', function (): void {
    // Same data as the reference run: backtest.js's loading, `matchInfo`, `daysToNext`,
    // `avgPts`, `mkt`, `feats` and `clip(y − p0)` run under Node on it (printed to 17
    // digits), with the warm-up cutoff at season start + 13 days. The research keeps
    // only rows with a known next value, so rows without one are counted apart.
    $this->season->update(['start_date' => '2026-09-01']);
    $teams = Team::factory()->count(3)->create();
    $players = [
        1 => forecastPlayer($this->season, [10_000_000, 10_150_000, 10_320_000, 10_280_000, 10_500_000, 10_910_000, 11_200_000, 11_150_000, 11_090_000, 11_400_000, 11_800_000], '2026-09-20', ['team_id' => $teams[0]->id]),
        2 => forecastPlayer($this->season, [3_000_000, 3_000_000, 2_990_000, 2_950_000, 2_940_000, 2_960_000, 2_900_000, 2_860_000, 2_800_000, 2_830_000, 2_830_000], '2026-09-20', ['team_id' => $teams[0]->id]),
        3 => forecastPlayer($this->season, [750_000, 760_000, 775_000, 790_000, 800_000, 815_000, 0, 830_000, 850_000, 870_000, 880_000], '2026-09-20', ['team_id' => $teams[1]->id]),
    ];
    $fixture = fn (string $date, int $local, int $guest, FixtureState $state): Fixture => Fixture::factory()->create([
        'season_id' => $this->season->id,
        'date' => $date,
        'team_local_id' => $teams[$local - 1]->id,
        'team_guest_id' => $teams[$guest - 1]->id,
        'state' => $state,
    ]);
    $fixtures = [
        1 => $fixture('2026-09-05 18:00:00', 1, 2, FixtureState::Finished),
        2 => $fixture('2026-09-13 20:00:00', 2, 1, FixtureState::Finished),
        3 => $fixture('2026-09-16 16:30:00', 2, 3, FixtureState::Finished),
        4 => $fixture('2026-09-17 21:00:00', 3, 1, FixtureState::Finished),
        5 => $fixture('2026-09-21 19:00:00', 2, 3, FixtureState::Scheduled),
        6 => $fixture('2026-09-26 19:00:00', 1, 2, FixtureState::Scheduled),
    ];

    foreach ([[1, 1, 6, 90], [1, 3, 0, 0], [2, 1, 10, 90], [2, 2, null, 20], [2, 3, 3, 90], [3, 3, 7, 45], [4, 1, -2, 60], [4, 2, 0, 0]] as [$fixtureKey, $playerKey, $points, $minutes]) {
        FixtureLineup::factory()->create([
            'fixture_id' => $fixtures[$fixtureKey]->id,
            'player_id' => $players[$playerKey]->id,
            'team_id' => $players[$playerKey]->team_id,
            'fantasy_points' => $points,
            'fantasy_stats' => ['mins_played' => [$minutes, 2]],
        ]);
    }

    $expected = [
        ['2026-09-14', 1, [1, 0.021400778210116732, -0.003875968992248062, 0.016748768472906402, 0.01022305851658114, 0.025276747202364794, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0.2, 0, 1, 0, 0.5211892990699383, 0.011153856594881948, 0, 0.021400778210116732, 0.5211892990699383], 0.017646840837502314],
        ['2026-09-14', 2, [1, -0.003389830508474576, -0.013377926421404682, -0.0033333333333333335, 0.01022305851658114, 0.009988095912930105, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, -0.0316526695878423, 0.0001072971850435332, 0, -0.003389830508474576, 0], 0.01019255159690995],
        ['2026-09-14', 3, [1, 0.012658227848101266, 0.01935483870967742, 0.019736842105263157, 0.01022305851658114, -0.006696610861576155, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, -0.5969100130080562, -0.007555822949469066, 0, 0.012658227848101266, -0.17907300390241687], 0.006091772151898734],
        ['2026-09-15', 1, [1, 0.039047619047619046, 0.021400778210116732, -0.003875968992248062, 0.02153344671201814, 0.017646840837502314, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 1, 0, 0.5378247505883422, 0.021000775975354314, 0, 0, 0], -0.012466500807472393],
        ['2026-09-15', 2, [1, 0.006802721088435374, -0.003389830508474576, -0.013377926421404682, 0.02153344671201814, 0.01019255159690995, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 1, 0, -0.028708288941061255, -0.00019529448259225342, 0, 0, 0], -0.027072991358705646],
        ['2026-09-16', 1, [1, 0.026581118240146653, 0.039047619047619046, 0.021400778210116732, 0.003155423984938191, -0.012466500807472393, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0.5492180226701819, 0.014598829200215652, 0, 0, 0], -0.031045403954432366],
        ['2026-09-16', 2, [1, -0.02027027027027027, 0.006802721088435374, -0.003389830508474576, 0.003155423984938191, -0.027072991358705646, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, -0.037602002101043475, 0.0007622027452914218, 0, 0, 0], 0.0064771668219944095],
        ['2026-09-17', 1, [1, -0.004464285714285714, 0.026581118240146653, 0.039047619047619046, -0.009128694581280787, -0.031045403954432366, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0.5472748673841794, -0.002443191372250801, 0, 0, 0], -0.0009168802049967974],
        ['2026-09-17', 2, [1, -0.013793103448275862, -0.02027027027027027, 0.006802721088435374, -0.009128694581280787, 0.0064771668219944095, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, -0.04363396687095733, 0.0006018478189097563, 0, 0, 0], -0.007185917530745118],
        ['2026-09-18', 1, [1, -0.0053811659192825115, -0.004464285714285714, 0.026581118240146653, -0.0007546004520449385, -0.0009168802049967974, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, -0.6666666666666667, 0, 0, 1, 0.5449315461491597, -0.0029323670644797835, 0, -0.0053811659192825115, -0.10898630922983195], 0.0333342768300129],
        ['2026-09-18', 2, [1, -0.02097902097902098, -0.013793103448275862, -0.02027027027027027, -0.0007546004520449385, -0.007185917530745118, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, -0.05284196865778057, 0.0011085727690443477, 0, -0.02097902097902098, 0], 0.031693306693306694],
        ['2026-09-19', 1, [1, 0.027953110910730387, -0.0053811659192825115, -0.004464285714285714, 0.020732269463240662, 0.0333342768300129, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0.5569048513364727, 0.01556722307613224, 0, 0, 0], 0.007134608387515225],
        ['2026-09-19', 2, [1, 0.010714285714285714, -0.02097902097902098, -0.013793103448275862, 0.020732269463240662, 0.031693306693306694, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 0, 0, -0.04821356447570935, -0.0005165739050968859, 0, 0, 0], -0.010714285714285714],
    ];

    $rows = app(ValueForecastFeatures::class)->rows($this->season->fresh(), '2026-09-20');
    $known = array_values(array_filter($rows, fn (ValueForecastRow $row): bool => $row->nextValue !== null));
    $unknown = array_map(fn (ValueForecastRow $row): array => [$row->referenceDate, $row->playerId], array_values(array_filter($rows, fn (ValueForecastRow $row): bool => $row->nextValue === null)));

    expect($known)->toHaveCount(count($expected))
        ->and($unknown)->toBe([['2026-09-15', $players[3]->id], ['2026-09-20', $players[1]->id], ['2026-09-20', $players[2]->id], ['2026-09-20', $players[3]->id]]);

    foreach ($expected as $index => [$date, $playerKey, $variables, $target]) {
        expect([$known[$index]->referenceDate, $known[$index]->playerId])->toBe([$date, $players[$playerKey]->id])
            ->and(ValueForecastFeatureVector::of($known[$index]))->toEqualWithDelta($variables, 1e-12)
            ->and(ValueForecastFeatureVector::target($known[$index], new ValueForecastParameters))->toEqualWithDelta($target, 1e-12);
    }
});
