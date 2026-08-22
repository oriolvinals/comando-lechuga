<?php

declare(strict_types=1);

namespace App\Enums;

enum PlayerSort: string
{
    case Points = 'points';
    case Value = 'value';
    case Difference = 'difference';

    public function column(): string
    {
        return match ($this) {
            self::Points => 'points',
            self::Value => 'market_value',
            self::Difference => 'market_value_difference',
        };
    }
}
