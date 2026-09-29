<?php

declare(strict_types=1);

namespace App\Services;

/**
 * A team's strength at a date, from squad value and recent performance. See
 * docs/superpowers/specs/2026-09-29-team-strength-design.md §2.1.
 *
 * The maths lives in the pure, static `fromInputs()` so it can be replayed by
 * `season:backtest-team-strength` with other parameters; gathering the
 * inputs from the database is a later task.
 */
final class TeamStrength
{
    /**
     * @param  list<TeamStrengthInputs>  $inputs
     * @return array<int, TeamStrengthRating> keyed by team id
     */
    public static function fromInputs(array $inputs, TeamStrengthParameters $parameters): array
    {
        $logValues = [];
        $goalDifferences = [];
        $shotsOnTargetDifferences = [];
        $keyPassesFor = [];
        $goalsFor = [];
        $shotsOnTargetFor = [];
        $failedToScoreRateInverted = [];
        $goalsAgainstInverted = [];
        $shotsOnTargetAgainstInverted = [];
        $keyPassesAgainstInverted = [];

        foreach ($inputs as $input) {
            $logValues[$input->teamId] = $input->logValue;
            $goalDifferences[$input->teamId] = $input->goalDifference;
            $shotsOnTargetDifferences[$input->teamId] = $input->shotsOnTargetDifference;
            $keyPassesFor[$input->teamId] = $input->keyPassesFor;
            $goalsFor[$input->teamId] = $input->goalsFor;
            $shotsOnTargetFor[$input->teamId] = $input->shotsOnTargetFor;
            $failedToScoreRateInverted[$input->teamId] = self::negate($input->failedToScoreRate);
            $goalsAgainstInverted[$input->teamId] = self::negate($input->goalsAgainst);
            $shotsOnTargetAgainstInverted[$input->teamId] = self::negate($input->shotsOnTargetAgainst);
            $keyPassesAgainstInverted[$input->teamId] = self::negate($input->keyPassesAgainst);
        }

        $valueZ = self::zScores($logValues);
        $goalDifferenceZ = self::zScores($goalDifferences);
        $shotsOnTargetDifferenceZ = self::zScores($shotsOnTargetDifferences);
        $keyPassesForZ = self::zScores($keyPassesFor);
        $goalsForZ = self::zScores($goalsFor);
        $shotsOnTargetForZ = self::zScores($shotsOnTargetFor);
        $failedToScoreRateZ = self::zScores($failedToScoreRateInverted);
        $goalsAgainstZ = self::zScores($goalsAgainstInverted);
        $shotsOnTargetAgainstZ = self::zScores($shotsOnTargetAgainstInverted);
        $keyPassesAgainstZ = self::zScores($keyPassesAgainstInverted);

        $ratings = [];

        foreach ($inputs as $input) {
            $teamId = $input->teamId;
            $weight = $input->matches / ($input->matches + $parameters->shrinkK);

            $performanceZ = (
                ($goalDifferenceZ[$teamId] ?? 0.0)
                + ($shotsOnTargetDifferenceZ[$teamId] ?? 0.0)
                + ($keyPassesForZ[$teamId] ?? 0.0)
            ) / 3;

            $attackZ = (
                ($goalsForZ[$teamId] ?? 0.0)
                + ($shotsOnTargetForZ[$teamId] ?? 0.0)
                + ($keyPassesForZ[$teamId] ?? 0.0)
                + ($failedToScoreRateZ[$teamId] ?? 0.0)
            ) / 4;

            $defenseZ = (
                ($goalsAgainstZ[$teamId] ?? 0.0)
                + ($shotsOnTargetAgainstZ[$teamId] ?? 0.0)
                + ($keyPassesAgainstZ[$teamId] ?? 0.0)
            ) / 3;

            $general = (1 - $weight) * $valueZ[$teamId] + $weight * $performanceZ;
            $offensiveThreat = (1 - $weight) * $valueZ[$teamId]
                + $weight * ((1 - $parameters->specificShare) * $performanceZ + $parameters->specificShare * $attackZ);
            $defensiveSolidity = (1 - $weight) * $valueZ[$teamId]
                + $weight * ((1 - $parameters->specificShare) * $performanceZ + $parameters->specificShare * $defenseZ);

            $ratings[$teamId] = new TeamStrengthRating(
                teamId: $teamId,
                matches: $input->matches,
                valueZ: $valueZ[$teamId],
                performanceZ: $performanceZ,
                attackZ: $attackZ,
                defenseZ: $defenseZ,
                general: $general,
                offensiveThreat: $offensiveThreat,
                defensiveSolidity: $defensiveSolidity,
            );
        }

        return $ratings;
    }

    private static function negate(?float $value): ?float
    {
        return $value === null ? null : -$value;
    }

    /**
     * Cross-team z-score of one metric, computed only among the teams whose
     * value is not null; a population standard deviation of 0 counts as 1.
     *
     * @param  array<int, float|null>  $values  keyed by team id
     * @return array<int, float> z-score keyed by team id, only for teams with a value
     */
    private static function zScores(array $values): array
    {
        $present = array_filter($values, fn (?float $value): bool => $value !== null);

        if ($present === []) {
            return [];
        }

        $mean = array_sum($present) / count($present);
        $variance = array_sum(array_map(
            fn (float $value): float => ($value - $mean) ** 2,
            $present,
        )) / count($present);
        $standardDeviation = sqrt($variance) ?: 1.0;

        return array_map(fn (float $value): float => ($value - $mean) / $standardDeviation, $present);
    }
}
