<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

enum PlayerPosition: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case Midfield = 'midfield';
    case Striker = 'striker';
    case Coach = 'coach';

    public static function fromFantasyId(int $positionId): self
    {
        return match ($positionId) {
            1 => self::Goalkeeper,
            2 => self::Defender,
            3 => self::Midfield,
            4 => self::Striker,
            5 => self::Coach,
            default => throw new InvalidArgumentException("Unknown player position ID: {$positionId}"),
        };
    }
}
