<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BadScoreRule;
use InvalidArgumentException;

/**
 * The tunable constants of the max bid model. The defaults are the model as
 * first specified; `season:backtest-max-bid --grid`, `--grid-decay` and
 * `--grid-streak` search around them. A calibration from those grids (decays
 * 0.75/0.8, sport rate 0.005, bench factor 0.25, one bench, bad score ≤ 2)
 * was tried and rolled back: its bids were too conservative for strong risers.
 */
final readonly class MaxBidParameters
{
    public function __construct(
        /** Daily fade of the increment when the next match is more than 7 days away (or there is none). */
        public float $incrementDecayBreak = 0.9,
        /** Daily fade of the increment when the next match is within 7 days. */
        public float $incrementDecayMatchweek = 0.9,
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
        /**
         * With 0 minutes in each of the team's last N finished matches the
         * increment is capped at 0 (0 = never). At most 3: only the team's
         * last three matches are gathered, so a larger N could never trigger.
         */
        public int $benchesBeforeUnprofitable = 0,
        public BadScoreRule $badScoreRule = BadScoreRule::Off,
        /**
         * Streak exception to the bad-score cap (null = off): the cap is
         * skipped when the player's own momentum pace (momentum / value, per
         * day) is at least this, his team took at least
         * `$streakExceptionTeamPoints` from its last three finished matches,
         * and he started all three. It never touches the bench rules.
         */
        public ?float $streakExceptionPace = null,
        /** Minimum team points (3 a win, 1 a draw) from its last three finished matches for the streak exception. */
        public int $streakExceptionTeamPoints = 7,
        /**
         * Daily fade of a strong riser's increment in a break (steady,
         * accelerating or sharply accelerating rise): such risers keep rising
         * through a mid-season break. Every other case uses the phase decay.
         */
        public float $decayStrongRiseBreak = 0.925,
    ) {
        $maximumBenches = count(MaxBidCalculator::RECENCY_WEIGHTS);

        if ($this->benchesBeforeUnprofitable < 0 || $this->benchesBeforeUnprofitable > $maximumBenches) {
            throw new InvalidArgumentException("benchesBeforeUnprofitable must be between 0 and {$maximumBenches}: only the team's last {$maximumBenches} matches are gathered.");
        }
    }
}
