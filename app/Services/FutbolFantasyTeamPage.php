<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The lineup block of a FútbolFantasy team page: "Posible alineación J{n}"
 * or, once the official lineup is out, "Alineación confirmada J{n}".
 */
final readonly class FutbolFantasyTeamPage
{
    /**
     * @param  list<FutbolFantasyPlayer>  $players  one per FF player id, in page order
     */
    public function __construct(
        public int $weekNumber,
        public bool $confirmed,
        public array $players,
    ) {}

    /**
     * The rival FF says the lineup is for — '' when no block carries one.
     */
    public function rivalCode(): string
    {
        foreach ($this->players as $player) {
            if ($player->rivalCode !== '') {
                return $player->rivalCode;
            }
        }

        return '';
    }
}
