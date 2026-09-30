<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The season-end prizes paid from the sanctions pot (70 €). The case order
 * is the page order: the four 10 € prizes first.
 */
enum SeasonPrize: string
{
    case NocheMagica = 'noche_magica';
    case ElAtracador = 'el_atracador';
    case ReyDelDomingo = 'rey_del_domingo';
    case BanquilloDeOro = 'banquillo_de_oro';
    case ElCriminal = 'el_criminal';
    case LaVictima = 'la_victima';
    case ElPupas = 'el_pupas';
    case Matrimonio = 'matrimonio';
    case FichajeDelPueblo = 'fichaje_del_pueblo';
    case HuecoLibre = 'hueco_libre';

    public function label(): string
    {
        return match ($this) {
            self::NocheMagica => 'Noche Mágica',
            self::ElAtracador => 'El Atracador',
            self::ReyDelDomingo => 'Rey del Domingo',
            self::BanquilloDeOro => 'El Banquillo de Oro',
            self::ElCriminal => 'El Criminal',
            self::LaVictima => 'La Víctima',
            self::ElPupas => 'El Pupas',
            self::Matrimonio => 'Matrimonio',
            self::FichajeDelPueblo => 'El Fichaje del Pueblo',
            self::HuecoLibre => 'Hueco libre',
        };
    }

    /** Euros. */
    public function amount(): int
    {
        return match ($this) {
            self::NocheMagica, self::ElAtracador, self::ReyDelDomingo, self::BanquilloDeOro => 10,
            default => 5,
        };
    }

    public function rule(): string
    {
        return match ($this) {
            self::NocheMagica => 'La mejor puntuación en una sola jornada de todo el año.',
            self::ElAtracador => 'El que más cláusulas paga.',
            self::ReyDelDomingo => 'El que más veces queda primero de la jornada.',
            self::BanquilloDeOro => 'El que más puntos deja sin alinear.',
            self::ElCriminal => 'El que más paga por encima del valor de mercado, en compras y cláusulas.',
            self::LaVictima => 'Al que más cláusulas le pagan.',
            self::ElPupas => 'El que más veces queda último de la jornada.',
            self::Matrimonio => 'La pareja mánager-jugador con más jornadas seguidas alineado.',
            self::FichajeDelPueblo => 'El jugador que pasa por más manos; se lo lleva quien más jornadas lo tuvo.',
            self::HuecoLibre => 'Lo proponéis vosotros.',
        };
    }

    /** False only for the free slot, whose category is still to be proposed. */
    public function isDecided(): bool
    {
        return $this !== self::HuecoLibre;
    }
}
