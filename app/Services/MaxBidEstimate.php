<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\MaxBidStatus;
use App\Models\Team;

/**
 * The max profitable bid for one player at one reference date, with the
 * factors that produced it. Factor fields are null when there is nothing to
 * estimate (no_data / unavailable).
 */
final readonly class MaxBidEstimate
{
    /**
     * @param  list<int>|null  $projection  day 0 (today) … day 14
     * @param  list<array{starter: bool, minutes: int}>  $recentParticipation  newest first
     * @param  list<array{team: Team, position: int, days_until: int, difficulty: float, weight: float}>  $upcomingRivals  soonest first
     */
    public function __construct(
        public MaxBidStatus $status,
        public int $value,
        public ?int $bid = null,
        public ?array $projection = null,
        public ?float $momentumIncrement = null,
        public ?float $marketAdjustment = null,
        public ?float $sportAdjustment = null,
        public ?float $dailyIncrement = null,
        public ?float $sportScore = null,
        public ?float $form = null,
        public ?float $participation = null,
        public array $recentParticipation = [],
        public ?float $rivalsEffect = null,
        public array $upcomingRivals = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'value' => $this->value,
            'bid' => $this->bid,
            'bid_premium' => $this->bid !== null && $this->value > 0 ? $this->bid / $this->value - 1 : null,
            'projection' => $this->projection,
            'projected_day7' => $this->projection[7] ?? null,
            'projected_day14' => $this->projection[14] ?? null,
            'momentum_increment' => $this->momentumIncrement,
            'market_adjustment' => $this->marketAdjustment,
            'sport_adjustment' => $this->sportAdjustment,
            'daily_increment' => $this->dailyIncrement,
            'sport_score' => $this->sportScore,
            'form' => $this->form,
            'participation' => $this->participation,
            'recent_participation' => $this->recentParticipation,
            'rivals_effect' => $this->rivalsEffect,
            'upcoming_rivals' => $this->upcomingRivals,
        ];
    }
}
