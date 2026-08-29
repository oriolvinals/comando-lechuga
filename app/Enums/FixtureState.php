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

    public static function fromWorldcup26Name(string $name): self
    {
        return match ($name) {
            'STATUS_FIRST_HALF' => self::FirstHalf,
            'STATUS_HALFTIME' => self::HalfTime,
            'STATUS_SECOND_HALF' => self::SecondHalf,
            'STATUS_FULL_TIME' => self::Finished,
            default => self::Scheduled,
        };
    }
}
