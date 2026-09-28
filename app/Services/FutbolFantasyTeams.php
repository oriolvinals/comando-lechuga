<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Which FútbolFantasy team page belongs to each of our teams, and the team
 * code FF writes in `data-rival` (the sanity check that a page is about the
 * fixture we store it on). Keyed by our teams.fantasy_id (LaLiga Fantasy's
 * team id) — a fixed map, like PLAYER_MAP in season:link-match-data-players.
 * A season team missing here is reported by season:sync-start-probabilities.
 */
final class FutbolFantasyTeams
{
    public const string TEAM_PAGE_URL = 'https://www.futbolfantasy.com/laliga/equipos/';

    /**
     * teams.fantasy_id => FútbolFantasy team page slug.
     *
     * @var array<int, string>
     */
    public const array SLUGS = [
        21 => 'alaves', // ALA - Deportivo Alavés
        3 => 'athletic', // ATH - Athletic Club
        2 => 'atletico', // ATM - Atlético de Madrid
        4 => 'barcelona', // BAR - FC Barcelona
        5 => 'betis', // BET - Real Betis
        6 => 'celta', // CEL - Celta
        26 => 'deportivo', // RCD - RC Deportivo
        7 => 'elche', // ELC - Elche CF
        8 => 'espanyol', // ESP - RCD Espanyol
        9 => 'getafe', // GET - Getafe CF
        11 => 'levante', // LEV - Levante UD
        12 => 'malaga', // MGA - Málaga CF
        13 => 'osasuna', // OSA - C.A. Osasuna
        49 => 'racing', // RAC - R. Racing Club
        14 => 'rayo-vallecano', // RAY - Rayo Vallecano
        15 => 'real-madrid', // RMA - Real Madrid
        16 => 'real-sociedad', // RSO - Real Sociedad
        17 => 'sevilla', // SEV - Sevilla FC
        18 => 'valencia', // VAL - Valencia CF
        20 => 'villarreal', // VIL - Villarreal CF
    ];

    /**
     * FútbolFantasy slug => the team code FF writes in `data-rival` / `data-equipo`.
     *
     * @var array<string, string>
     */
    public const array CODES = [
        'alaves' => 'ALA',
        'athletic' => 'ATH',
        'atletico' => 'ATM',
        'barcelona' => 'BAR',
        'betis' => 'BET',
        'celta' => 'CEL',
        'deportivo' => 'DEP',
        'elche' => 'ELC',
        'espanyol' => 'ESP',
        'getafe' => 'GET',
        'levante' => 'LEV',
        'malaga' => 'MLG',
        'osasuna' => 'OSA',
        'racing' => 'RAC',
        'rayo-vallecano' => 'RAY',
        'real-madrid' => 'RMD',
        'real-sociedad' => 'RSO',
        'sevilla' => 'SEV',
        'valencia' => 'VAL',
        'villarreal' => 'VIL',
    ];

    public static function slugFor(int $fantasyId): ?string
    {
        return self::SLUGS[$fantasyId] ?? null;
    }

    public static function codeFor(int $fantasyId): ?string
    {
        $slug = self::slugFor($fantasyId);

        return $slug === null ? null : (self::CODES[$slug] ?? null);
    }

    public static function pageUrlFor(int $fantasyId): ?string
    {
        $slug = self::slugFor($fantasyId);

        return $slug === null ? null : self::TEAM_PAGE_URL.$slug;
    }
}
