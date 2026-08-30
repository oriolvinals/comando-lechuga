<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchPositionLine: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case DefensiveMidfielder = 'defensive_midfielder';
    case Midfielder = 'midfielder';
    case AttackingMidfielder = 'attacking_midfielder';
    case Forward = 'forward';
    case Substitute = 'substitute';
    case Unknown = 'unknown';

    // worldcup26 sometimes labels the midfield with a defensive/attacking
    // split ("Defensive Midfielder", "Attacking Midfielder Left") instead of
    // one flat "Midfielder" line — checked before the generic Midfielder
    // case so those formations (e.g. 4-2-3-1) get their own depth line
    // instead of being flattened onto a single crowded row.
    public static function fromWorldcup26Text(string $text): self
    {
        return match (true) {
            str_contains($text, 'Goalkeeper') => self::Goalkeeper,
            str_contains($text, 'Back') || str_contains($text, 'Defender') => self::Defender,
            str_contains($text, 'Defensive Midfielder') => self::DefensiveMidfielder,
            str_contains($text, 'Attacking Midfielder') => self::AttackingMidfielder,
            str_contains($text, 'Midfielder') => self::Midfielder,
            str_contains($text, 'Forward') => self::Forward,
            $text === 'Substitute' => self::Substitute,
            default => self::Unknown,
        };
    }
}
