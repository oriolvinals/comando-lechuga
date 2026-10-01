<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Fits MaxBidParameters::$confidenceCalibration: for each chosen confidence
 * (50…95 %), the confidence the bid must be solved at so the realised
 * probability (the chance the best offer of the lock, on the real path,
 * beats the bid) matches it on average.
 */
final class MaxBidCalibrationFit
{
    /** Lowest confidence a knot may solve at (a target already met there gets this). */
    public const float MIN_CONFIDENCE = 0.5;

    /** Highest confidence a knot may solve at (a knot that can't reach its target gets this). */
    public const float MAX_CONFIDENCE = 0.99;

    /**
     * Scans the solved-at confidence from 0,50 to 0,99 over the cases and
     * turns the resulting curve into knots. Null without any case.
     *
     * @param  list<array{0: list<int>, 1: list<int>}>  $cases  [projection, real values], day 0…14
     * @return array<int, float>|null
     */
    public static function fit(array $cases): ?array
    {
        if ($cases === []) {
            return null;
        }

        $curve = [];

        for ($step = (int) round(self::MIN_CONFIDENCE * 100); $step <= (int) round(self::MAX_CONFIDENCE * 100); $step++) {
            $sum = 0.0;

            foreach ($cases as [$projection, $actual]) {
                $sum += 1 - MaxBidCalculator::bestOfferProbabilityAtMost(MaxBidCalculator::solveBid($projection, $step / 100), $actual);
            }

            $curve[] = [$step / 100, $sum / count($cases)];
        }

        return self::knotsFromCurve($curve);
    }

    /**
     * Each knot is the confidence where the curve first reaches its target,
     * interpolated linearly between the two points around it: the first
     * point's confidence when the target is met from the start, the maximum
     * when it is never met, and never below the previous knot.
     *
     * @param  list<array{0: float, 1: float}>  $curve  solved-at confidence → mean realised probability, ascending confidence
     * @return array<int, float>
     */
    public static function knotsFromCurve(array $curve): array
    {
        $knots = [];
        $previous = 0.0;

        foreach (range(50, 95, 5) as $percent) {
            $target = $percent / 100;
            $knot = self::MAX_CONFIDENCE;

            foreach ($curve as $index => [$confidence, $realised]) {
                if ($realised < $target) {
                    continue;
                }

                if ($index === 0) {
                    $knot = $confidence;
                } else {
                    [$previousConfidence, $previousRealised] = $curve[$index - 1];
                    $knot = $previousConfidence + ($target - $previousRealised) / max($realised - $previousRealised, 1e-9) * ($confidence - $previousConfidence);
                }

                break;
            }

            $knot = round(max($previous, $knot), 4);
            $knots[$percent] = $knot;
            $previous = $knot;
        }

        return $knots;
    }
}
