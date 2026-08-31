<?php

declare(strict_types=1);

namespace App\Enums;

use InvalidArgumentException;

enum SeasonActivityType: string
{
    case Buyout = 'buyout';
    case Shield = 'shield';
    case WeeklyPrize = 'weekly_prize';
    case JoinedLeague = 'joined_league';
    case Signing = 'signing';
    case Sale = 'sale';

    public static function fromFantasyId(int $activityTypeId): self
    {
        return match ($activityTypeId) {
            1 => self::Buyout,
            4 => self::Shield,
            6 => self::WeeklyPrize,
            9 => self::JoinedLeague,
            31 => self::Signing,
            33 => self::Sale,
            default => throw new InvalidArgumentException("Unknown activity type ID: {$activityTypeId}"),
        };
    }

    /**
     * Spanish display label — mirrors resources/js/components/activity-helpers.tsx's TYPE_LABELS.
     */
    public function label(): string
    {
        return match ($this) {
            self::Buyout => 'Cláusula',
            self::Shield => 'Blindaje',
            self::WeeklyPrize => 'Premio semanal',
            self::JoinedLeague => 'Nuevo manager',
            self::Signing => 'Fichaje',
            self::Sale => 'Venta',
        };
    }
}
