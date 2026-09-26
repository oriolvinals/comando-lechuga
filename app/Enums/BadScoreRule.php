<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * When a player's most recent fantasy score is bad enough that his value is
 * expected to stop rising (the max bid model then caps his increment at 0).
 */
enum BadScoreRule: string
{
    case Off = 'off';
    case AtMostOne = 'at_most_one';
    case AtMostTwo = 'at_most_two';
    case BelowFortyPercentOfAverage = 'below_40_percent_of_average';

    public function isBadScore(int $points, float $seasonAverage): bool
    {
        return match ($this) {
            self::Off => false,
            self::AtMostOne => $points <= 1,
            self::AtMostTwo => $points <= 2,
            self::BelowFortyPercentOfAverage => $points < 0.4 * $seasonAverage,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Off => 'no',
            self::AtMostOne => '≤ 1',
            self::AtMostTwo => '≤ 2',
            self::BelowFortyPercentOfAverage => '< 40 % media',
        };
    }
}
