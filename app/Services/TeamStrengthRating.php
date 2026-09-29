<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DifficultyVariant;

/**
 * A team's strength at one date, in z units across the season's teams. The
 * `*Z` fields are kept for tooltips and the backtest; `general`,
 * `offensiveThreat` and `defensiveSolidity` are the ratings `MatchDifficulty`
 * actually uses.
 */
final readonly class TeamStrengthRating
{
    public function __construct(
        public int $teamId,
        public int $matches,
        public float $valueZ,
        public float $performanceZ,
        public float $attackZ,
        public float $defenseZ,
        public float $general,
        public float $offensiveThreat,
        public float $defensiveSolidity,
    ) {}

    /**
     * The rating a rival contributes to a match's difficulty for the given
     * variant: `Attack` asks whether this team can be scored against
     * (its defensive solidity), `Defense` whether it can score
     * (its offensive threat).
     */
    public function for(DifficultyVariant $variant): float
    {
        return match ($variant) {
            DifficultyVariant::General => $this->general,
            DifficultyVariant::Attack => $this->defensiveSolidity,
            DifficultyVariant::Defense => $this->offensiveThreat,
        };
    }
}
