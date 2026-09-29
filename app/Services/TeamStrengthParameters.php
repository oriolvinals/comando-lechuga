<?php

declare(strict_types=1);

namespace App\Services;

/**
 * The tunable constants of the team strength model, with the defaults from
 * docs/superpowers/specs/2026-09-29-team-strength-design.md §2.3.
 * `season:backtest-team-strength --grid` searches around `shrinkK`,
 * `homeBonus` and `specificShare`.
 */
final readonly class TeamStrengthParameters
{
    public function __construct(
        /** How many of a team's highest-valued players count toward its squad value. */
        public int $topPlayers = 15,
        /** Pseudo-matches shrinking the performance weight toward the squad value alone. */
        public int $shrinkK = 8,
        /** Added to the home team's ease of play, in z units. */
        public float $homeBonus = 0.4,
        /** Share of a variant's rating that comes from its attack/defense split, the rest from the general performance. */
        public float $specificShare = 0.3,
        /** Weight of the missing-starters adjustment on the general and attack variants. */
        public float $absenceWeight = 0.3,
        /** Minimum share of a team's disputed minutes for a player to count as a regular starter. */
        public float $regularMinutesShare = 0.7,
        /** Start probability, in percent, below which a player counts as absent. */
        public int $absentProbabilityBelow = 30,
        /** Slope from the internal ease score to the 0-10 difficulty scale. */
        public float $scaleSlope = 2.5,
    ) {}
}
