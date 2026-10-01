<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use InvalidArgumentException;
use RuntimeException;

/**
 * Ridge least squares by normal equations accumulated one row at a time, so
 * a walk-forward adds each day's rows once instead of refitting from scratch
 * (port of backtest.js `ols`). Column 0 is the intercept and is never
 * penalised.
 */
final class HybridRegression
{
    /** @var array<int, array<int, float>> XᵀX, `features` × `features` */
    private array $xtx;

    /** @var array<int, float> Xᵀy, `features` long */
    private array $xty;

    private int $count = 0;

    public function __construct(private readonly int $features, private readonly float $lambda)
    {
        $this->xtx = array_fill(0, $features, array_fill(0, $features, 0.0));
        $this->xty = array_fill(0, $features, 0.0);
    }

    /**
     * @param  list<float>  $x
     */
    public function add(array $x, float $y): void
    {
        if (count($x) !== $this->features) {
            throw new InvalidArgumentException("Expected {$this->features} features, got ".count($x).'.');
        }

        foreach ($x as $a => $xa) {
            if ($xa === 0.0) {
                continue;
            }

            $this->xty[$a] += $xa * $y;

            foreach ($x as $c => $xc) {
                $this->xtx[$a][$c] += $xa * $xc;
            }
        }

        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * Gaussian elimination with partial pivoting.
     *
     * @return list<float>
     */
    public function solve(): array
    {
        $size = $this->features;
        $matrix = [];

        for ($a = 0; $a < $size; $a++) {
            $row = $this->xtx[$a];

            if ($a > 0) {
                $row[$a] += $this->lambda * $this->count;
            }

            $row[] = $this->xty[$a];
            $matrix[] = $row;
        }

        for ($i = 0; $i < $size; $i++) {
            $pivot = $i;

            for ($j = $i + 1; $j < $size; $j++) {
                if (abs($matrix[$j][$i]) > abs($matrix[$pivot][$i])) {
                    $pivot = $j;
                }
            }

            [$matrix[$i], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$i]];

            if (abs($matrix[$i][$i]) < 1e-15) {
                throw new RuntimeException('The value forecast regression is singular (too few rows).');
            }

            for ($j = $i + 1; $j < $size; $j++) {
                $factor = $matrix[$j][$i] / $matrix[$i][$i];

                for ($c = $i; $c <= $size; $c++) {
                    $matrix[$j][$c] -= $factor * $matrix[$i][$c];
                }
            }
        }

        $coefficients = array_fill(0, $size, 0.0);

        for ($i = $size - 1; $i >= 0; $i--) {
            $sum = $matrix[$i][$size];

            for ($c = $i + 1; $c < $size; $c++) {
                $sum -= $matrix[$i][$c] * $coefficients[$c];
            }

            // "+ 0.0" turns a -0.0 (an all-zero column) into 0.0.
            $coefficients[$i] = $sum / $matrix[$i][$i] + 0.0;
        }

        return array_values($coefficients);
    }
}
