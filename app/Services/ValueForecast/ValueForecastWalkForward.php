<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Models\Season;
use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use Carbon\CarbonImmutable;
use Generator;

/**
 * Fits the model day after day with only what was known at each reference
 * date, adding each day's newly known rows to the regression once. Rows are
 * built one day at a time and only the residual window's days are kept, so
 * memory stays flat however long the season gets. The scheduled forecast is
 * its last day; the backtests replay many.
 */
final class ValueForecastWalkForward
{
    public function __construct(
        private readonly ValueForecastFeatures $features,
        private readonly ValueForecastParameters $parameters = new ValueForecastParameters,
    ) {}

    /**
     * For each reference date `d` in range: fits on every row with a known
     * outcome dated `d` or earlier, takes the residuals of those dated
     * `d − (residualWindowDays − 1)` … `d` and forecasts every row of `d`.
     * A date with too few training rows, no residuals or no rows is skipped.
     *
     * @return Generator<int, ValueForecastDay>
     */
    public function days(Season $season, string $firstReferenceDate, string $lastReferenceDate): Generator
    {
        $regression = new HybridRegression(Vector::SIZE, $this->parameters->lambda);

        /** @var array<string, list<ValueForecastRow>> $known reference date → its rows with a known outcome, the residual window's days only */
        $known = [];

        foreach ($this->features->rowsByDay($season, $lastReferenceDate) as $reference => $rows) {
            foreach ($known[self::shift($reference, -1)] ?? [] as $row) {
                $regression->add(Vector::of($row), Vector::target($row, $this->parameters));
            }

            $oldestWindowReference = self::shift($reference, -$this->parameters->residualWindowDays);
            $known = array_filter($known, fn (string $date): bool => $date >= $oldestWindowReference, ARRAY_FILTER_USE_KEY);

            if ($reference >= $firstReferenceDate && $rows !== [] && $regression->count() >= $this->parameters->minimumTrainingRows) {
                $model = (new ValueForecastModel($regression->solve(), $this->parameters))->withResiduals(self::flatten($known));

                if ($model->hasResiduals()) {
                    yield new ValueForecastDay(
                        $reference,
                        $model,
                        $regression->count(),
                        array_map(fn (ValueForecastRow $row): ValueForecastPrediction => $model->predict($row), $rows),
                    );
                }
            }

            $known[$reference] = array_values(array_filter($rows, fn (ValueForecastRow $row): bool => $row->nextValue !== null));
        }
    }

    /**
     * @return array<int, array<string, int>> player id → reference date → predicted value of the next day
     */
    public function predictedValues(Season $season, string $firstReferenceDate, string $lastReferenceDate): array
    {
        $values = [];

        foreach ($this->days($season, $firstReferenceDate, $lastReferenceDate) as $day) {
            foreach ($day->predictions as $prediction) {
                $values[$prediction->row->playerId][$day->referenceDate] = $prediction->predictedValue();
            }
        }

        return $values;
    }

    /**
     * @param  array<string, list<ValueForecastRow>>  $rowsByDay
     * @return Generator<int, ValueForecastRow>
     */
    private static function flatten(array $rowsByDay): Generator
    {
        foreach ($rowsByDay as $rows) {
            yield from $rows;
        }
    }

    private static function shift(string $date, int $days): string
    {
        return CarbonImmutable::parse($date, 'UTC')->addDays($days)->toDateString();
    }
}
