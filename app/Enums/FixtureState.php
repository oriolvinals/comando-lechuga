<?php

declare(strict_types=1);

namespace App\Enums;

enum FixtureState: string
{
    case Scheduled = 'scheduled';
    case FirstHalf = 'first_half';
    case HalfTime = 'half_time';
    case SecondHalf = 'second_half';
    case Finished = 'finished';

    public static function fromFantasyId(int $stateId): self
    {
        return match ($stateId) {
            2 => self::FirstHalf,
            3 => self::HalfTime,
            4 => self::SecondHalf,
            7 => self::Finished,
            default => self::Scheduled,
        };
    }
}
