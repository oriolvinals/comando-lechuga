<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a player's market value is moving: the direction of the recent pace
 * (rise/fall) and whether that pace is speeding up, holding or slowing down
 * compared to the pace just before it.
 */
enum MarketTrend: string
{
    case PositiveInflection = 'positive_inflection';
    case RiseAcceleratingSharply = 'rise_accelerating_sharply';
    case RiseAccelerating = 'rise_accelerating';
    case RiseSteady = 'rise_steady';
    case RiseDecelerating = 'rise_decelerating';
    case RiseDeceleratingSharply = 'rise_decelerating_sharply';
    case NegativeInflection = 'negative_inflection';
    case FallDeceleratingSharply = 'fall_decelerating_sharply';
    case FallDecelerating = 'fall_decelerating';
    case FallSteady = 'fall_steady';
    case FallAccelerating = 'fall_accelerating';
    case FallAcceleratingSharply = 'fall_accelerating_sharply';

    /** Days in each of the two compared windows (recent pace vs. the pace before it). */
    private const int WINDOW_DAYS = 3;

    /** Daily relative change below which a window counts as flat (0.05 %/day). */
    private const float NOISE_THRESHOLD = 0.0005;

    /**
     * Classifies the trend from the daily market values, oldest first. Only the
     * last seven values are used: the pace of the last three days is compared
     * with the pace of the three days before it. When the last day moved against
     * that trend, it is an inflection in the last day's direction, so the trend
     * always agrees with the sign of the latest daily difference.
     *
     * @param  list<int>  $values
     */
    public static function fromDailyValues(array $values): ?self
    {
        $values = array_slice(array_values($values), -(self::WINDOW_DAYS * 2 + 1));

        if (count($values) < self::WINDOW_DAYS * 2 + 1 || $values[0] <= 0 || $values[self::WINDOW_DAYS] <= 0) {
            return null;
        }

        $trend = self::fromPaces($values);
        $lastDayDifference = $values[self::WINDOW_DAYS * 2] - $values[self::WINDOW_DAYS * 2 - 1];

        if ($trend === null || $lastDayDifference === 0 || $trend->isRising() === $lastDayDifference > 0) {
            return $trend;
        }

        return $lastDayDifference > 0 ? self::PositiveInflection : self::NegativeInflection;
    }

    /** Whether the market value is currently rising under this trend. */
    public function isRising(): bool
    {
        return match ($this) {
            self::PositiveInflection,
            self::RiseAcceleratingSharply,
            self::RiseAccelerating,
            self::RiseSteady,
            self::RiseDecelerating,
            self::RiseDeceleratingSharply => true,
            default => false,
        };
    }

    /**
     * @param  list<int>  $values  Exactly seven daily values, oldest first.
     */
    private static function fromPaces(array $values): ?self
    {
        $previousPace = self::dailyPace($values[0], $values[self::WINDOW_DAYS]);
        $recentPace = self::dailyPace($values[self::WINDOW_DAYS], $values[self::WINDOW_DAYS * 2]);
        $previousDirection = self::direction($previousPace);
        $recentDirection = self::direction($recentPace);

        if ($recentDirection === 0 && $previousDirection === 0) {
            return null;
        }

        if ($recentDirection === 0) {
            return $previousDirection > 0 ? self::RiseDeceleratingSharply : self::FallDeceleratingSharply;
        }

        if ($previousDirection === -$recentDirection) {
            return $recentDirection > 0 ? self::PositiveInflection : self::NegativeInflection;
        }

        $rising = $recentDirection > 0;

        if ($previousDirection === 0) {
            return $rising ? self::RiseAcceleratingSharply : self::FallAcceleratingSharply;
        }

        $paceRatio = abs($recentPace) / abs($previousPace);

        return match (true) {
            $paceRatio >= 2 => $rising ? self::RiseAcceleratingSharply : self::FallAcceleratingSharply,
            $paceRatio >= 1.25 => $rising ? self::RiseAccelerating : self::FallAccelerating,
            $paceRatio >= 0.8 => $rising ? self::RiseSteady : self::FallSteady,
            $paceRatio >= 0.5 => $rising ? self::RiseDecelerating : self::FallDecelerating,
            default => $rising ? self::RiseDeceleratingSharply : self::FallDeceleratingSharply,
        };
    }

    private static function dailyPace(int $from, int $to): float
    {
        return ($to - $from) / $from / self::WINDOW_DAYS;
    }

    private static function direction(float $pace): int
    {
        if (abs($pace) < self::NOISE_THRESHOLD) {
            return 0;
        }

        return $pace > 0 ? 1 : -1;
    }
}
