<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MaxBidStatus;
use App\Models\Team;

/**
 * Everything the max bid formula needs about one player at one reference
 * date, gathered from the database once so the formula can be replayed with
 * different parameters (see `MaxBidCalculator::estimateFromInputs()`).
 */
final readonly class MaxBidInputs
{
    /** Days to the next match up to which it is a matchweek; beyond, a break. */
    public const int MATCHWEEK_MAX_DAYS = 7;

    /**
     * @param  list<int>  $lastPoints  fantasy points of the player's last three finished lineups, newest first (pending points as 0)
     * @param  list<array{starter: bool, minutes: int}>  $recentParticipation  the team's last three finished matches, newest first
     * @param  list<array{team: Team, position: int, days_until: int, difficulty: float}>  $upcomingRivals  soonest first; `difficulty` is MatchDifficulty's `rivalEase` (−1 hard … +1 easy), not its 0–10 difficulty
     * @param  list<int>  $recentTeamPoints  the team's points (3 a win, 1 a draw, 0 a loss) in the same matches as `$recentParticipation`, newest first
     */
    public function __construct(
        public int $value,
        /** Set when there is nothing to estimate (unavailable / no data); every other field is then unused. */
        public ?MaxBidStatus $presetStatus = null,
        /** Average daily value change over the momentum window. */
        public float $momentum = 0.0,
        /** Value-weighted daily pace of the whole market. */
        public float $marketPace = 0.0,
        public array $lastPoints = [],
        /** Average fantasy points of all the player's finished lineups of the season. */
        public float $seasonPointsAverage = 0.0,
        public array $recentParticipation = [],
        public array $upcomingRivals = [],
        public int $teamCount = 2,
        public bool $doubtful = false,
        public array $recentTeamPoints = [],
        /** The market day the values come from (Y-m-d): the latest published day up to the requested date. */
        public ?string $referenceDate = null,
        /** Whether his market trend over his last seven values is a steady, accelerating or sharply accelerating rise. */
        public bool $strongRise = false,
        /**
         * FútbolFantasy's chance (0–1) that he starts his next match: its
         * latest % fetched up to the requested moment, or 1 / 0 once he is
         * confirmed as a starter / substitute. Null without a row (or a
         * figure) for that match.
         */
        public ?float $nextStartProbability = null,
    ) {}

    /** Fantasy points of the most recent finished lineup, null when he has none. */
    public function latestPoints(): ?int
    {
        return $this->lastPoints[0] ?? null;
    }

    public function daysToNextMatch(): ?int
    {
        return $this->upcomingRivals[0]['days_until'] ?? null;
    }

    public function isBreak(): bool
    {
        $daysToNextMatch = $this->daysToNextMatch();

        return $daysToNextMatch === null || $daysToNextMatch > self::MATCHWEEK_MAX_DAYS;
    }
}
