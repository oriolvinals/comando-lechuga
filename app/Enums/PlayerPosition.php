<?php

namespace App\Enums;

use InvalidArgumentException;

enum PlayerPosition: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case Midfielder = 'midfielder';
    case Forward = 'forward';
    case Coach = 'coach';

    public static function fromFantasyId(int $positionId): self
    {
        return match ($positionId) {
            1 => self::Goalkeeper,
            2 => self::Defender,
            3 => self::Midfielder,
            4 => self::Forward,
            5 => self::Coach,
            default => throw new InvalidArgumentException("Unknown player position ID: {$positionId}"),
        };
    }
}
