<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use Carbon\CarbonImmutable;

/**
 * One reference date of the walk-forward: the model fitted with what was
 * known then, and its forecast for every row of that date.
 */
final readonly class ValueForecastDay
{
    /**
     * @param  list<ValueForecastPrediction>  $predictions
     */
    public function __construct(
        public string $referenceDate,
        public ValueForecastModel $model,
        public int $trainingRows,
        public array $predictions,
    ) {}

    public function targetDate(): string
    {
        return CarbonImmutable::parse($this->referenceDate)->addDay()->toDateString();
    }
}
