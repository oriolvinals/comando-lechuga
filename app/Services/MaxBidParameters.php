<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BadScoreRule;

/**
 * The tunable constants of the max bid model. The defaults are the model as
 * specified; `season:backtest-max-bid --grid` searches around them.
 */
final readonly class MaxBidParameters
{
    public const float DEFAULT_INCREMENT_DECAY = 0.9;

    public function __construct(
        /** Daily fade of the increment when the next match is more than 7 days away (or there is none). */
        public float $incrementDecayBreak = self::DEFAULT_INCREMENT_DECAY,
        /** Daily fade of the increment when the next match is within 7 days. */
        public float $incrementDecayMatchweek = self::DEFAULT_INCREMENT_DECAY,
        /** Share of the value a sport score of ±1 adds or removes per day. */
        public float $sportDailyRate = 0.01,
        public float $formWeight = 0.4,
        public float $participationWeight = 0.3,
        public float $rivalsWeight = 0.3,
        /** Days after which an upcoming match weighs half. */
        public float $proximityHalfLifeDays = 7.0,
        /** Participation below which a player counts as a bench player. */
        public float $benchParticipation = 0.4,
        /** Factor applied to a bench player's positive increment. */
        public float $benchIncrementFactor = 0.5,
        /** With 0 minutes in each of the team's last N finished matches the increment is capped at 0 (0 = never). */
        public int $benchesBeforeUnprofitable = 0,
        public BadScoreRule $badScoreRule = BadScoreRule::Off,
    ) {}
}
