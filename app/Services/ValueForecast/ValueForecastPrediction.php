<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

/**
 * Tomorrow's forecast for one row: the change (a fraction), its 80 %
 * interval, the chance it goes up and the reasons with their impact.
 */
final readonly class ValueForecastPrediction
{
    /**
     * @param  list<array{kind: string, label: string, impact_pct: float}>  $reasons
     */
    public function __construct(
        public ValueForecastRow $row,
        public float $change,
        public float $lowChange,
        public float $highChange,
        public float $upProbability,
        public array $reasons,
    ) {}

    public function predictedValue(): int
    {
        return (int) round($this->row->value * (1 + $this->change));
    }

    public function low(): int
    {
        return (int) round($this->row->value * (1 + $this->lowChange));
    }

    public function high(): int
    {
        return (int) round($this->row->value * (1 + $this->highChange));
    }

    /**
     * @return 'up'|'stable'|'down'
     */
    public function direction(float $stableBand): string
    {
        return match (true) {
            $this->change > $stableBand => 'up',
            $this->change < -$stableBand => 'down',
            default => 'stable',
        };
    }
}
