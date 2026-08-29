<?php

declare(strict_types=1);

namespace App\Enums;

enum MatchPositionLine: string
{
    case Goalkeeper = 'goalkeeper';
    case Defender = 'defender';
    case Midfielder = 'midfielder';
    case Forward = 'forward';
    case Substitute = 'substitute';
    case Unknown = 'unknown';

    public static function fromWorldcup26Text(string $text): self
    {
        return match (true) {
            str_contains($text, 'Goalkeeper') => self::Goalkeeper,
            str_contains($text, 'Back') || str_contains($text, 'Defender') => self::Defender,
            str_contains($text, 'Midfielder') => self::Midfielder,
            str_contains($text, 'Forward') => self::Forward,
            $text === 'Substitute' => self::Substitute,
            default => self::Unknown,
        };
    }
}
