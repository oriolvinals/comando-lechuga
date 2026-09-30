<?php

declare(strict_types=1);

namespace App\Services;

/** Weights of the radar's 0–100 opportunity score (see ClauseRadar::opportunity). */
final readonly class ClauseOpportunityParameters
{
    public function __construct(
        public float $valueRatioCap = 1.2,
        public float $formFloor = 0.25,
        public float $formWeight = 0.75,
        public float $averageTarget = 5.0,
    ) {}
}
