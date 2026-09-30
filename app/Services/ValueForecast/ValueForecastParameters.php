<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

/**
 * The constants of the hybrid "persistence + match shock" value forecast
 * (spec docs/superpowers/specs/2026-09-30-value-forecast-design.md §1).
 * The defaults are the backtested model of comando-lechuga-research/value-forecast.
 */
final readonly class ValueForecastParameters
{
    public function __construct(
        /** Lowest daily change LaLiga Fantasy ever publishes (−3,46 %). */
        public float $floor = -0.0346,
        /** Ridge strength, added as λ·n to every diagonal term but the intercept. */
        public float $lambda = 1e-4,
        /** The fitted target `y − p0` is clipped to ±this. */
        public float $targetClip = 0.1,
        /** Days of residuals behind the 80 % interval and P(up) (T−34 … T). */
        public int $residualWindowDays = 35,
        /** Rows start this many days after the season start (earlier values are flat padding). */
        public int $warmupDays = 13,
        /** No forecast below this many training rows. */
        public int $minimumTrainingRows = 100,
        /** |change| up to this is "stable". */
        public float $stableBand = 0.005,
        public float $lowQuantile = 0.1,
        public float $highQuantile = 0.9,
        /** Reasons kept besides the inertia. */
        public int $maximumReasons = 3,
        /** Reasons below this |impact| (a fraction: 0.001 = 0,1 pp) are dropped. */
        public float $minimumReasonImpact = 0.001,
    ) {}
}
