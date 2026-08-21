<?php

declare(strict_types=1);

namespace App\Enums;

enum FixtureState: string
{
    case Scheduled = 'scheduled';
    case Finished = 'finished';

    public static function fromFantasyId(int $stateId): self
    {
        return match ($stateId) {
            7 => self::Finished,
            default => self::Scheduled,
        };
    }
}
