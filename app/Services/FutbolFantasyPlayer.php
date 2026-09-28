<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerPosition;

/**
 * One player block of a FútbolFantasy team page.
 */
final readonly class FutbolFantasyPlayer
{
    /**
     * @param  int  $futbolfantasyId  FF's player id (`jugador_{id}`)
     * @param  string  $name  FF's short name (`.truncate-name`), '' if the block has none
     * @param  string  $slug  FF's player slug (`/jugadores/{slug}/…`), '' if none
     * @param  int|null  $probability  `data-probabilidad` "NN%" as 0–100; null when there is no %
     * @param  bool|null  $confirmedStarter  `data-probabilidad` "Titular" (true) / "Suplente" (false); null while only predicted
     * @param  bool  $predictedStarter  in FF's probable XI (`data-onceFF="titular"`)
     * @param  string  $rivalCode  `data-rival`, e.g. "VIL"
     * @param  int  $marketValue  `data-valor-laliga-fantasy`, LaLiga Fantasy's market value to the euro
     * @param  int  $totalPoints  `data-puntos-totales-laliga-fantasy`
     * @param  PlayerPosition|null  $position  `data-posicionLaLigaFantasy`
     * @param  int|null  $pitchX  the probable-XI shirt's `left: X%` on FF's pitch, 0–100 (0 = the team's left touchline — FF attacks up); null off the pitch (bench shirts sit in px rows)
     * @param  int|null  $pitchY  the probable-XI shirt's `top: Y%` on FF's pitch, 0–100 (0 = the rival goal line, ~87 = the goalkeeper); null off the pitch
     */
    public function __construct(
        public int $futbolfantasyId,
        public string $name,
        public string $slug,
        public ?int $probability,
        public ?bool $confirmedStarter,
        public bool $predictedStarter,
        public string $rivalCode,
        public int $marketValue,
        public int $totalPoints,
        public ?PlayerPosition $position,
        public ?int $pitchX = null,
        public ?int $pitchY = null,
    ) {}

    /**
     * The same player seen in another block of the page (FF prints every
     * player twice — pitch and list): this block's values win, the other
     * only fills what this one lacks.
     */
    public function withFallback(self $other): self
    {
        return new self(
            futbolfantasyId: $this->futbolfantasyId,
            name: $this->name !== '' ? $this->name : $other->name,
            slug: $this->slug !== '' ? $this->slug : $other->slug,
            probability: $this->probability ?? $other->probability,
            confirmedStarter: $this->confirmedStarter ?? $other->confirmedStarter,
            predictedStarter: $this->predictedStarter,
            rivalCode: $this->rivalCode !== '' ? $this->rivalCode : $other->rivalCode,
            marketValue: $this->marketValue > 0 ? $this->marketValue : $other->marketValue,
            totalPoints: $this->totalPoints,
            position: $this->position ?? $other->position,
            pitchX: $this->pitchX ?? $other->pitchX,
            pitchY: $this->pitchY ?? $other->pitchY,
        );
    }
}
