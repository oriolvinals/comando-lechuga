<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DifficultyVariant;

/**
 * How hard one match is for one of its teams, on the 0–10 scale of
 * docs/superpowers/specs/2026-09-29-team-strength-design.md §2.2 (10 = very
 * hard). `rivalEase` is the same figure on the −1…+1 "easy is positive" scale
 * `MaxBidCalculator` expects; nothing else should use it.
 */
final readonly class MatchDifficultyResult
{
    public function __construct(
        /** 0–10, one decimal, 10 = hardest. */
        public float $difficulty,
        /** (5 − difficulty) / 5, for MaxBidCalculator only. */
        public float $rivalEase,
        public DifficultyVariant $variant,
        /** Whether the rival's missing regulars lowered the difficulty. */
        public bool $absenceAdjusted,
        /** The rival's standings position, informative only. */
        public ?int $rivalPosition,
        /**
         * Each term's contribution to the internal ease `e` (higher = easier), for tooltips.
         *
         * @var array{rival_strength: float, home: float, absences: float}
         */
        public array $components,
    ) {}

    /**
     * @return array{difficulty: float, difficulty_variant: string, difficulty_components: array{rival_strength: float, home: float, absences: float}}
     */
    public function toArray(): array
    {
        return [
            'difficulty' => $this->difficulty,
            'difficulty_variant' => $this->variant->value,
            'difficulty_components' => $this->components,
        ];
    }
}
