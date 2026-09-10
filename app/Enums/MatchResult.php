<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchResult: string
{
    case Win = 'win';
    case Draw = 'draw';
    case Loss = 'loss';

    public static function fromScore(int $for, int $against): self
    {
        return match (true) {
            $for > $against => self::Win,
            $for === $against => self::Draw,
            default => self::Loss,
        };
    }
}
