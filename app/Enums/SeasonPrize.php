<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The season-end prizes paid from the sanctions pot (70 €). The case order
 * is the page order: the four 10 € prizes first.
 */
enum SeasonPrize: string
{
    case BestNight = 'best_night';
    case MostBuyoutsMade = 'most_buyouts_made';
    case SundayKing = 'sunday_king';
    case BenchPoints = 'bench_points';
    case MostOverpaid = 'most_overpaid';
    case MostBuyoutsSuffered = 'most_buyouts_suffered';
    case WorstWeeks = 'worst_weeks';
    case LongestPartnership = 'longest_partnership';
    case MostOwnedPlayer = 'most_owned_player';
    case OpenSlot = 'open_slot';

    public function label(): string
    {
        return match ($this) {
            self::BestNight => 'Noche Mágica',
            self::MostBuyoutsMade => 'El Atracador',
            self::SundayKing => 'Rey del Domingo',
            self::BenchPoints => 'El Banquillo de Oro',
            self::MostOverpaid => 'El Criminal',
            self::MostBuyoutsSuffered => 'La Víctima',
            self::WorstWeeks => 'El Pupas',
            self::LongestPartnership => 'Matrimonio',
            self::MostOwnedPlayer => 'El Fichaje del Pueblo',
            self::OpenSlot => 'Hueco libre',
        };
    }

    /** Euros. */
    public function amount(): int
    {
        return match ($this) {
            self::BestNight, self::MostBuyoutsMade, self::SundayKing, self::BenchPoints => 10,
            default => 5,
        };
    }

    public function rule(): string
    {
        return match ($this) {
            self::BestNight => 'La mejor puntuación en una sola jornada de todo el año.',
            self::MostBuyoutsMade => 'El que más cláusulas paga.',
            self::SundayKing => 'El que más veces queda primero de la jornada.',
            self::BenchPoints => 'El que más puntos deja sin alinear.',
            self::MostOverpaid => 'El que más paga por encima del valor de mercado, en compras y cláusulas.',
            self::MostBuyoutsSuffered => 'Al que más cláusulas le pagan.',
            self::WorstWeeks => 'El que más veces queda último de la jornada.',
            self::LongestPartnership => 'La pareja mánager-jugador con más jornadas seguidas alineado.',
            self::MostOwnedPlayer => 'El jugador que pasa por más manos; se lo lleva quien más jornadas lo tuvo.',
            self::OpenSlot => 'Lo proponéis vosotros.',
        };
    }

    /** False only for the free slot, whose category is still to be proposed. */
    public function isDecided(): bool
    {
        return $this !== self::OpenSlot;
    }
}
