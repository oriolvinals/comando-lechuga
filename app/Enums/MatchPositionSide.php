<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The flank of a worldcup26 lineup position, from the player's own point of
 * view (facing the rival goal). "Center Left" / "Center Right" are their own
 * sides so a pair of centre-backs or strikers keeps its real order.
 */
enum MatchPositionSide: string
{
    case Left = 'left';
    case CenterLeft = 'center_left';
    case Center = 'center';
    case CenterRight = 'center_right';
    case Right = 'right';

    public static function fromWorldcup26Text(string $text): self
    {
        $hasCenter = str_contains($text, 'Center');
        $hasLeft = str_contains($text, 'Left');
        $hasRight = str_contains($text, 'Right');

        return match (true) {
            $hasCenter && $hasLeft => self::CenterLeft,
            $hasCenter && $hasRight => self::CenterRight,
            $hasCenter => self::Center,
            $hasLeft => self::Left,
            $hasRight => self::Right,
            default => self::Center,
        };
    }

    /** Order from the player's left flank (0) to his right flank (4). */
    public function leftToRight(): int
    {
        return match ($this) {
            self::Left => 0,
            self::CenterLeft => 1,
            self::Center => 2,
            self::CenterRight => 3,
            self::Right => 4,
        };
    }
}
