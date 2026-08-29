<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchPositionSide: string
{
    case Left = 'left';
    case Center = 'center';
    case Right = 'right';

    public static function fromWorldcup26Text(string $text): self
    {
        return match (true) {
            str_contains($text, 'Left') => self::Left,
            str_contains($text, 'Right') => self::Right,
            default => self::Center,
        };
    }
}
