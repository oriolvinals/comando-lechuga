<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Services\ValueForecast\ValueForecastFeatureVector as Vector;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * The hybrid "persistence + match shock" model for one fit: tomorrow's
 * change = max(floor, today's change + w·x), an 80 % interval and P(up)
 * from recent residuals split by "his team played yesterday", and the
 * reasons behind it (spec §1.3–1.4).
 *
 * @phpstan-import-type MatchInfo from ValueForecastRow
 */
final class ValueForecastModel
{
    /** @var array<string, list<int>> Reason kind → the variables it adds up. */
    private const array GROUPS = [
        'streak' => [Vector::CHANGE_TODAY, Vector::CHANGE_YESTERDAY, Vector::CHANGE_BEFORE, Vector::ACCELERATION, Vector::CHANGE_X_LOG_VALUE, Vector::AT_FLOOR, Vector::CHANGE_IF_MATCH_YESTERDAY],
        'market' => [Vector::MARKET],
        'match_yesterday' => [Vector::YESTERDAY, Vector::YESTERDAY + 1, Vector::YESTERDAY + 2, Vector::YESTERDAY + 3, Vector::YESTERDAY + 4, Vector::POINTS_VS_AVERAGE, Vector::POINTS_X_LOG_VALUE],
        'match_today' => [Vector::TODAY, Vector::TODAY + 1, Vector::TODAY + 2, Vector::TODAY + 3, Vector::TODAY + 4],
        'match_before' => [Vector::BEFORE, Vector::BEFORE + 1, Vector::BEFORE + 2, Vector::BEFORE + 3, Vector::BEFORE + 4],
        'calendar' => [Vector::MATCH_TOMORROW, Vector::MATCH_WITHIN_3_DAYS, Vector::BREAK_OVER_7_DAYS],
        'baseline' => [Vector::CONSTANT, Vector::LOG_VALUE],
    ];

    /** @var array{0: list<float>, 1: list<float>} Sorted residuals: [no match yesterday, match yesterday]. */
    private array $residuals = [[], []];

    /**
     * @param  list<float>  $coefficients
     */
    public function __construct(
        public readonly array $coefficients,
        private readonly ValueForecastParameters $parameters,
    ) {}

    public function change(ValueForecastRow $row): float
    {
        return max($this->parameters->floor, $this->rawChange(Vector::of($row), $row));
    }

    /**
     * A copy whose interval and P(up) come from these rows' residuals
     * (rows without a known outcome are skipped).
     *
     * @param  iterable<ValueForecastRow>  $rows
     */
    public function withResiduals(iterable $rows): self
    {
        $model = clone $this;
        $model->residuals = [[], []];

        foreach ($rows as $row) {
            $actual = $row->actualChange();

            if ($actual !== null) {
                $model->residuals[$row->matchYesterday['team'] ? 1 : 0][] = $actual - $this->change($row);
            }
        }

        sort($model->residuals[0]);
        sort($model->residuals[1]);

        return $model;
    }

    public function predict(ValueForecastRow $row): ValueForecastPrediction
    {
        $x = Vector::of($row);
        $raw = $this->rawChange($x, $row);
        $change = max($this->parameters->floor, $raw);
        $pool = $this->pool($row);

        if ($pool === []) {
            [$low, $high, $upProbability] = [$change, $change, $change > 0 ? 1.0 : 0.0];
        } else {
            $low = max($this->parameters->floor, $change + self::quantile($pool, $this->parameters->lowQuantile));
            $high = $change + self::quantile($pool, $this->parameters->highQuantile);
            $upProbability = (count($pool) - self::countAtMost($pool, -$change)) / count($pool);
        }

        return new ValueForecastPrediction($row, $change, $low, $high, $upProbability, $this->reasons($row, $x, $raw, $change));
    }

    /**
     * @return array{match_yesterday: array{size: int, low: float|null, high: float|null}, no_match_yesterday: array{size: int, low: float|null, high: float|null}}
     */
    public function quantiles(): array
    {
        return ['match_yesterday' => $this->describe($this->residuals[1]), 'no_match_yesterday' => $this->describe($this->residuals[0])];
    }

    /**
     * @param  list<float>  $pool
     * @return array{size: int, low: float|null, high: float|null}
     */
    private function describe(array $pool): array
    {
        return [
            'size' => count($pool),
            'low' => $pool === [] ? null : self::quantile($pool, $this->parameters->lowQuantile),
            'high' => $pool === [] ? null : self::quantile($pool, $this->parameters->highQuantile),
        ];
    }

    /**
     * @param  list<float>  $x
     */
    private function rawChange(array $x, ValueForecastRow $row): float
    {
        $change = $row->changeToday;

        foreach ($this->coefficients as $index => $coefficient) {
            $change += $coefficient * $x[$index];
        }

        return $change;
    }

    /**
     * @return list<float>
     */
    private function pool(ValueForecastRow $row): array
    {
        $group = $this->residuals[$row->matchYesterday['team'] ? 1 : 0];

        return $group !== [] ? $group : $this->residuals[0];
    }

    /**
     * @param  list<float>  $sorted
     */
    private static function quantile(array $sorted, float $probability): float
    {
        return $sorted[(int) floor($probability * (count($sorted) - 1))];
    }

    /**
     * How many sorted values are ≤ `$threshold` (binary search).
     *
     * @param  list<float>  $sorted
     */
    private static function countAtMost(array $sorted, float $threshold): int
    {
        $low = 0;
        $high = count($sorted);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($sorted[$middle] <= $threshold) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /**
     * @param  list<float>  $x
     * @return list<array{kind: string, label: string, impact_pct: float}>
     */
    private function reasons(ValueForecastRow $row, array $x, float $raw, float $change): array
    {
        $impacts = [];

        foreach (self::GROUPS as $kind => $indexes) {
            $impact = 0.0;

            foreach ($indexes as $index) {
                $impact += $this->coefficients[$index] * $x[$index];
            }

            $impacts[$kind] = $impact;
        }

        if ($change > $raw) {
            $impacts['floor'] = $change - $raw;
        }

        $impacts = array_filter($impacts, fn (float $impact): bool => abs($impact) >= $this->parameters->minimumReasonImpact);
        uasort($impacts, fn (float $a, float $b): int => abs($b) <=> abs($a));

        $reasons = [self::reason('inertia', $row->changeToday, $row)];

        foreach (array_slice($impacts, 0, $this->parameters->maximumReasons, true) as $kind => $impact) {
            $reasons[] = self::reason($kind, $impact, $row);
        }

        return $reasons;
    }

    /**
     * @return array{kind: string, label: string, impact_pct: float}
     */
    private static function reason(string $kind, float $impact, ValueForecastRow $row): array
    {
        return ['kind' => $kind, 'label' => self::label($kind, $impact, $row), 'impact_pct' => round($impact * 100, 2)];
    }

    private static function label(string $kind, float $impact, ValueForecastRow $row): string
    {
        $day = fn (int $offset): string => CarbonImmutable::parse($row->referenceDate)->addDays($offset)->format('d/m');

        return match ($kind) {
            'inertia' => 'Inercia: cambio de hoy',
            'streak' => $impact < 0 ? 'Freno de racha' : 'Racha',
            'market' => 'Mercado general',
            'match_yesterday' => self::matchLabel('Partido del '.$day(-1), $row->matchYesterday),
            'match_today' => self::matchLabel('Partido de hoy', $row->matchToday),
            'match_before' => self::matchLabel('Partido del '.$day(-2), $row->matchBefore),
            'calendar' => match (true) {
                $row->daysToNextMatch >= 30 => 'Sin partido próximo',
                $row->daysToNextMatch === 1 => 'Próximo partido en 1 día',
                default => "Próximo partido en {$row->daysToNextMatch} días",
            },
            'baseline' => 'Nivel de valor',
            'floor' => 'Suelo diario −3,46 %',
            default => throw new LogicException("Unknown value forecast reason {$kind}."),
        };
    }

    /**
     * @param  MatchInfo  $match
     */
    private static function matchLabel(string $prefix, array $match): string
    {
        return $match['played'] ? "{$prefix} · {$match['points']} pts" : "{$prefix} · sin jugar";
    }
}
