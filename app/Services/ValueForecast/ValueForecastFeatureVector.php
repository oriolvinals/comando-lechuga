<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use LogicException;

/**
 * The 30 variables of the hybrid model, in the fixed order of the research
 * (comando-lechuga-research/value-forecast/backtest.js `feats`).
 *
 * @phpstan-import-type MatchInfo from ValueForecastRow
 */
final class ValueForecastFeatureVector
{
    public const int SIZE = 30;

    /** @var list<string> */
    public const array BUCKETS = ['nomin', 'low', 'mid', 'good', 'great'];

    public const int CONSTANT = 0;

    public const int CHANGE_TODAY = 1;

    public const int CHANGE_YESTERDAY = 2;

    public const int CHANGE_BEFORE = 3;

    public const int MARKET = 4;

    public const int ACCELERATION = 5;

    /** First of the five buckets of T − 1's match (6…10). */
    public const int YESTERDAY = 6;

    /** First of the five buckets of T's match (11…15). */
    public const int TODAY = 11;

    /** First of the five buckets of T − 2's match (16…20). */
    public const int BEFORE = 16;

    public const int POINTS_VS_AVERAGE = 21;

    public const int MATCH_TOMORROW = 22;

    public const int MATCH_WITHIN_3_DAYS = 23;

    public const int BREAK_OVER_7_DAYS = 24;

    public const int LOG_VALUE = 25;

    public const int CHANGE_X_LOG_VALUE = 26;

    public const int AT_FLOOR = 27;

    public const int CHANGE_IF_MATCH_YESTERDAY = 28;

    public const int POINTS_X_LOG_VALUE = 29;

    /**
     * @param  MatchInfo  $match
     */
    public static function bucket(array $match): ?string
    {
        return match (true) {
            !$match['team'] => null,
            !$match['played'] => 'nomin',
            $match['points'] <= 2 => 'low',
            $match['points'] <= 5 => 'mid',
            $match['points'] <= 9 => 'good',
            default => 'great',
        };
    }

    /**
     * @return list<float>
     */
    public static function of(ValueForecastRow $row): array
    {
        $logValue = log10(max($row->value, 1)) - 6.5;
        $x = [1.0, $row->changeToday, $row->changeYesterday, $row->changeBefore, $row->marketChange, $row->changeToday - $row->changeYesterday];

        foreach ([$row->matchYesterday, $row->matchToday, $row->matchBefore] as $match) {
            $bucket = self::bucket($match);

            foreach (self::BUCKETS as $name) {
                $x[] = $bucket === $name ? 1.0 : 0.0;
            }
        }

        $yesterday = $row->matchYesterday;
        $x[] = $yesterday['played'] ? ($yesterday['points'] - $row->averagePoints) / 10 : 0.0;
        $x[] = $row->daysToNextMatch <= 1 ? 1.0 : 0.0;
        $x[] = $row->daysToNextMatch <= 3 ? 1.0 : 0.0;
        $x[] = $row->daysToNextMatch > 7 ? 1.0 : 0.0;
        $x[] = $logValue;
        $x[] = $row->changeToday * $logValue;
        $x[] = $row->changeToday < -0.03 ? 1.0 : 0.0;
        $x[] = $yesterday['team'] ? $row->changeToday : 0.0;
        $x[] = $yesterday['played'] ? max(-5, min(20, $yesterday['points'])) / 10 * $logValue : 0.0;

        return $x;
    }

    /** What the regression fits: tomorrow's change over persistence, clipped. Rows need a known outcome. */
    public static function target(ValueForecastRow $row, ValueForecastParameters $parameters): float
    {
        $actual = $row->actualChange() ?? throw new LogicException('A training row needs a known next value.');

        return max(-$parameters->targetClip, min($parameters->targetClip, $actual - $row->changeToday));
    }
}
