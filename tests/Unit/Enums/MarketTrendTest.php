<?php

use App\Enums\MarketTrend;

test('classifies the market trend from the last seven daily values', function (array $values, ?MarketTrend $expected): void {
    expect(MarketTrend::fromDailyValues($values))->toBe($expected);
})->with([
    'rise after a fall' => [[1000, 990, 980, 970, 980, 990, 1000], MarketTrend::PositiveInflection],
    'rise more than doubling its pace' => [[1000, 1010, 1020, 1030, 1060, 1090, 1120], MarketTrend::RiseAcceleratingSharply],
    'rise picking up pace' => [[1000, 1010, 1020, 1030, 1045, 1060, 1075], MarketTrend::RiseAccelerating],
    'rise at the same pace' => [[1000, 1010, 1020, 1030, 1040, 1050, 1060], MarketTrend::RiseSteady],
    'rise losing pace' => [[1000, 1020, 1040, 1060, 1073, 1086, 1099], MarketTrend::RiseDecelerating],
    'rise almost stalled' => [[1000, 1030, 1060, 1090, 1095, 1100, 1105], MarketTrend::RiseDeceleratingSharply],
    'rise that stopped' => [[1000, 1030, 1060, 1090, 1090, 1090, 1090], MarketTrend::RiseDeceleratingSharply],
    'fall after a rise' => [[1000, 1010, 1020, 1030, 1020, 1010, 1000], MarketTrend::NegativeInflection],
    'fall almost stalled' => [[1000, 970, 940, 910, 905, 900, 895], MarketTrend::FallDeceleratingSharply],
    'fall losing pace' => [[1000, 980, 960, 940, 930, 920, 910], MarketTrend::FallDecelerating],
    'fall at the same pace' => [[1000, 990, 980, 970, 960, 950, 940], MarketTrend::FallSteady],
    'fall picking up pace' => [[1000, 990, 980, 970, 955, 940, 925], MarketTrend::FallAccelerating],
    'fall more than doubling its pace' => [[1000, 990, 980, 970, 940, 910, 880], MarketTrend::FallAcceleratingSharply],
    'fall whose last day rose' => [[1000, 970, 940, 910, 880, 850, 860], MarketTrend::PositiveInflection],
    'rise whose last day fell' => [[1000, 1030, 1060, 1090, 1120, 1150, 1140], MarketTrend::NegativeInflection],
    'stalled rise whose last day fell' => [[1000, 1030, 1060, 1090, 1091, 1092, 1091], MarketTrend::NegativeInflection],
    'rise out of a flat stretch' => [[1000, 1000, 1000, 1000, 1010, 1020, 1030], MarketTrend::RiseAcceleratingSharply],
    'movement below the noise threshold' => [[1000000, 1000100, 1000200, 1000300, 1000200, 1000100, 1000000], null],
    'flat' => [[1000, 1000, 1000, 1000, 1000, 1000, 1000], null],
    'fewer than seven days' => [[1000, 1010, 1020, 1030, 1040, 1050], null],
    'zero value' => [[0, 0, 0, 0, 1010, 1020, 1030], null],
]);

test('only the last seven daily values are taken into account', function (): void {
    expect(MarketTrend::fromDailyValues([5000, 1, 1000, 1010, 1020, 1030, 1040, 1050, 1060]))
        ->toBe(MarketTrend::RiseSteady);
});
