<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A team's aggregates at one date, gathered once so `TeamStrength::fromInputs()`
 * can be replayed in the backtest with other parameters. Every performance
 * figure is a per-match mean over that team's finished matches before the
 * date; it is null when the team has no such match (or, for key passes, no
 * match with that data).
 */
final readonly class TeamStrengthInputs
{
    public function __construct(
        public int $teamId,
        public int $matches,
        /** ln(sum of the top `topPlayers` squad values). */
        public float $logValue,
        public ?float $goalDifference,
        public ?float $shotsOnTargetDifference,
        public ?float $keyPassesFor,
        public ?float $goalsFor,
        public ?float $shotsOnTargetFor,
        public ?float $failedToScoreRate,
        public ?float $goalsAgainst,
        public ?float $shotsOnTargetAgainst,
        public ?float $keyPassesAgainst,
    ) {}
}
