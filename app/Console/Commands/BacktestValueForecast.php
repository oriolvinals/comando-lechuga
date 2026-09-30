<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\ValueForecast\ValueForecastParameters;
use App\Services\ValueForecast\ValueForecastRow;
use App\Services\ValueForecast\ValueForecastWalkForward;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:backtest-value-forecast {--from= : First target date (Y-m-d)} {--to= : Last target date (Y-m-d)}')]
#[Description('Replay the value forecast walk-forward over the market history and compare it with persistence and the max bid momentum (writes nothing)')]
class BacktestValueForecast extends Command
{
    /** Day-1 momentum of the max bid without adjustments: (v − v₋₃) / 3 × 0,9. */
    private const float MOMENTUM_DECAY = 0.9;

    public function handle(ValueForecastWalkForward $walkForward, ValueForecastParameters $parameters): int
    {
        $season = Season::current();
        $latest = PlayerMarket::query()->max('date');

        if ($latest === null) {
            $this->error('No hay histórico de mercado que reproducir.');

            return self::FAILURE;
        }

        $toOption = $this->option('to');
        $fromOption = $this->option('from');
        $lastTarget = is_string($toOption) ? CarbonImmutable::parse($toOption) : CarbonImmutable::parse(substr((string) $latest, 0, 10));
        $firstTarget = is_string($fromOption)
            ? CarbonImmutable::parse($fromOption)
            : $season->start_date->addDays($parameters->warmupDays + $parameters->residualWindowDays);

        /** @var array<string, list<array{row: ValueForecastRow, pred: float, low: float|null, high: float|null}>> $results */
        $results = ['Persistencia' => [], 'Momentum puja máx.' => [], 'Híbrido' => []];

        /** @var array<int, list<int>> $calibration decile → outcomes (1 = rose) */
        $calibration = [];

        foreach ($walkForward->days($season, $firstTarget->subDay()->toDateString(), $lastTarget->subDay()->toDateString()) as $day) {
            foreach ($day->predictions as $prediction) {
                $row = $prediction->row;
                $actual = $row->actualChange();

                if ($actual === null) {
                    continue;
                }

                $results['Persistencia'][] = ['row' => $row, 'pred' => $row->changeToday, 'low' => null, 'high' => null];
                $results['Momentum puja máx.'][] = ['row' => $row, 'pred' => $this->momentum($row), 'low' => null, 'high' => null];
                $results['Híbrido'][] = ['row' => $row, 'pred' => $prediction->change, 'low' => $prediction->lowChange, 'high' => $prediction->highChange];
                $calibration[min(9, (int) floor($prediction->upProbability * 10))][] = $actual > 0 ? 1 : 0;
            }
        }

        if ($results['Híbrido'] === []) {
            $this->info('No hay previsiones que evaluar en ese periodo.');

            return self::SUCCESS;
        }

        $segments = [
            "Todo ({$firstTarget->toDateString()} a {$lastTarget->toDateString()})" => fn (ValueForecastRow $row): bool => true,
            'Mañana = D+2 de un partido del equipo' => fn (ValueForecastRow $row): bool => $row->matchYesterday['team'],
            'Sin partido del equipo ayer' => fn (ValueForecastRow $row): bool => !$row->matchYesterday['team'],
            'Valor ≥ 5 M' => fn (ValueForecastRow $row): bool => $row->value >= 5_000_000,
            'Giros (mañana cambia de signo)' => fn (ValueForecastRow $row): bool => ($row->actualChange() > 0) !== ($row->changeToday > 0),
        ];

        foreach ($segments as $title => $filter) {
            $this->newLine();
            $this->info($title);
            $this->table(
                ['Predictor', 'n', 'Signo', '3 clases', 'Error medio €', 'Error mediano €', 'Error pp', 'Cobertura 80 %'],
                array_map(fn (string $name): array => $this->metricsRow($name, array_values(array_filter($results[$name], fn (array $entry): bool => $filter($entry['row']))), $parameters), array_keys($results)),
            );
        }

        ksort($calibration);
        $this->newLine();
        $this->info('Calibración de P(sube) del híbrido');
        $this->table(['Predicho', 'Real', 'n'], array_map(
            fn (int $decile, array $outcomes): array => [($decile * 10).'–'.($decile * 10 + 10).' %', $this->percent(array_sum($outcomes) / count($outcomes)), count($outcomes)],
            array_keys($calibration),
            $calibration,
        ));

        return self::SUCCESS;
    }

    /** The max bid's day-1 increment as a fraction of today's value, from the row's last three changes. */
    private function momentum(ValueForecastRow $row): float
    {
        $valueThreeDaysAgo = $row->value / ((1 + $row->changeToday) * (1 + $row->changeYesterday) * (1 + $row->changeBefore));

        return ($row->value - $valueThreeDaysAgo) / 3 * self::MOMENTUM_DECAY / $row->value;
    }

    /**
     * @param  list<array{row: ValueForecastRow, pred: float, low: float|null, high: float|null}>  $entries
     * @return list<string|int>
     */
    private function metricsRow(string $name, array $entries, ValueForecastParameters $parameters): array
    {
        if ($entries === []) {
            return [$name, 0, '—', '—', '—', '—', '—', '—'];
        }

        $class = fn (float $change): int => $change > $parameters->stableBand ? 1 : ($change < -$parameters->stableBand ? -1 : 0);
        $sign = 0;
        $classes = 0;
        $errorsEuro = [];
        $errorPp = 0.0;
        $covered = 0;
        $withInterval = 0;

        foreach ($entries as ['row' => $row, 'pred' => $pred, 'low' => $low, 'high' => $high]) {
            $actual = (float) $row->actualChange();
            $sign += ($pred > 0) === ($actual > 0) ? 1 : 0;
            $classes += $class($pred) === $class($actual) ? 1 : 0;
            $errorsEuro[] = abs($row->value * (1 + $pred) - (int) $row->nextValue);
            $errorPp += abs($pred - $actual) * 100;

            if ($low !== null && $high !== null) {
                $withInterval++;
                $covered += $actual >= $low && $actual <= $high ? 1 : 0;
            }
        }

        sort($errorsEuro);
        $count = count($entries);

        return [
            $name,
            $count,
            $this->percent($sign / $count),
            $this->percent($classes / $count),
            number_format(array_sum($errorsEuro) / $count, 0, ',', '.'),
            number_format($errorsEuro[intdiv($count, 2)], 0, ',', '.'),
            number_format($errorPp / $count, 2, ',', '.'),
            $withInterval === 0 ? '—' : $this->percent($covered / $withInterval),
        ];
    }

    private function percent(float $share): string
    {
        return number_format($share * 100, 1, ',', '.').' %';
    }
}
