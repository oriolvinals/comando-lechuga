<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which rival strength a match difficulty is measured against. See
 * docs/superpowers/specs/2026-09-29-team-strength-design.md §2.2.
 */
enum DifficultyVariant: string
{
    case General = 'general';
    case Attack = 'attack';
    case Defense = 'defense';

    /** Goalkeepers and defenders face the rival's attack; midfielders and strikers face its defense. */
    public static function forPosition(?PlayerPosition $position): self
    {
        return match ($position) {
            PlayerPosition::Goalkeeper, PlayerPosition::Defender => self::Defense,
            PlayerPosition::Midfield, PlayerPosition::Striker => self::Attack,
            PlayerPosition::Coach, null => self::General,
        };
    }
}
