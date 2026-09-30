<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

/**
 * What is known about one player at the end of `referenceDate` (T), to
 * forecast the value dated `targetDate` (T + 1). Changes are fractions
 * (0.02 = +2 %). A match array says whether his team played a finished
 * match that day, whether he played minutes, and his fantasy points
 * (pending points count as 0).
 *
 * @phpstan-type MatchInfo array{team: bool, played: bool, points: int}
 */
final readonly class ValueForecastRow
{
    /**
     * @param  MatchInfo  $matchYesterday  T − 1
     * @param  MatchInfo  $matchToday  T
     * @param  MatchInfo  $matchBefore  T − 2
     */
    public function __construct(
        public int $playerId,
        public string $referenceDate,
        public string $targetDate,
        public int $value,
        public float $changeToday,
        public float $changeYesterday,
        public float $changeBefore,
        /** Mean daily change of every player with values on T and T − 1. */
        public float $marketChange,
        public array $matchYesterday,
        public array $matchToday,
        public array $matchBefore,
        /** Days from T to his team's next match (any state), at most 30. */
        public int $daysToNextMatch,
        /** Mean fantasy points of his matches with minutes before T. */
        public float $averagePoints,
        /** The published value on T + 1, null while unknown. */
        public ?int $nextValue = null,
    ) {}

    public function actualChange(): ?float
    {
        return $this->nextValue === null ? null : ($this->nextValue - $this->value) / $this->value;
    }
}
