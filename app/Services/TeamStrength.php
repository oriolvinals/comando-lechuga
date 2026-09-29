<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\PlayerMarket;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * A team's strength at a date, from squad value and recent performance. See
 * docs/superpowers/specs/2026-09-29-team-strength-design.md §2.1.
 *
 * The maths lives in the pure, static `fromInputs()` so it can be replayed by
 * `season:backtest-team-strength` with other parameters; `inputsAt()` gathers
 * it from the database with a handful of aggregated queries (never one per
 * team or per team-match), and `ratingsAt()` memoizes the result per instance
 * like `MaxBidCalculator` does, keyed by season and minute. For the current
 * hour, `ratingsAt()` also caches the inputs across requests for an hour.
 */
final class TeamStrength
{
    /** @var array<string, array<int, TeamStrengthRating>> */
    private array $ratingsMemo = [];

    /**
     * `fromInputs()`'s ratings for the season's teams at `$at` with the
     * default parameters, memoized per instance so repeated calls for the
     * same season and minute don't repeat the database work.
     *
     * @return array<int, TeamStrengthRating> keyed by team id
     */
    public function ratingsAt(Season $season, CarbonInterface $at): array
    {
        $key = "{$season->id}:".CarbonImmutable::parse($at)->format('Y-m-d H:i');

        return $this->ratingsMemo[$key] ??= self::fromInputs(
            $this->cachedInputsAt($season, $at),
            new TeamStrengthParameters,
        );
    }

    /**
     * `inputsAt()`, cached for an hour (as plain arrays) when `$at` falls in
     * the current hour, so the lineup aggregation runs at most once an hour
     * per season. Any other time — a backtest or an explicit past date — is
     * computed fresh, so no stale data crosses dates.
     *
     * @return list<TeamStrengthInputs>
     */
    private function cachedInputsAt(Season $season, CarbonInterface $at): array
    {
        $hour = CarbonImmutable::parse($at)->format('Y-m-d H');

        if ($hour !== CarbonImmutable::now()->format('Y-m-d H')) {
            return $this->inputsAt($season, $at);
        }

        /** @var list<array{teamId: int, matches: int, logValue: float|null, goalDifference: float|null, shotsOnTargetDifference: float|null, keyPassesFor: float|null, goalsFor: float|null, shotsOnTargetFor: float|null, failedToScoreRate: float|null, goalsAgainst: float|null, shotsOnTargetAgainst: float|null, keyPassesAgainst: float|null}> $rows */
        $rows = Cache::remember(
            "team-strength:inputs:{$season->id}:{$hour}",
            3600,
            fn (): array => array_map(fn (TeamStrengthInputs $inputs): array => $inputs->toArray(), $this->inputsAt($season, $at)),
        );

        return array_map(TeamStrengthInputs::fromArray(...), $rows);
    }

    /**
     * Every team of the season's aggregates as of `$at`, looking only at data
     * dated before it: squad value from the most recent published market day
     * up to `$at` (`MaxBidCalculator::referenceDate()`'s rule), and per-match
     * means from finished fixtures dated before `$at`. Public so
     * `season:backtest-team-strength` can replay it without the memo.
     *
     * @return list<TeamStrengthInputs>
     */
    public function inputsAt(Season $season, CarbonInterface $at): array
    {
        $teamIds = $season->teams()->orderBy('teams.id')->pluck('teams.id');

        if ($teamIds->isEmpty()) {
            return [];
        }

        $logValues = $this->squadLogValues($teamIds, $at);
        $matchAggregates = $this->matchAggregates($season, $at);

        return array_values($teamIds
            ->map(fn (int $teamId): TeamStrengthInputs => self::teamInputs(
                $teamId,
                $logValues[$teamId] ?? null,
                $matchAggregates[$teamId] ?? null,
            ))
            ->all());
    }

    /**
     * @param  Collection<int, int>  $teamIds
     * @return array<int, float> ln(sum of the topPlayers highest values) keyed by team id, only for teams with any
     */
    private function squadLogValues(Collection $teamIds, CarbonInterface $at): array
    {
        $referenceDate = $this->referenceDate($at);

        if ($referenceDate === null) {
            return [];
        }

        $topPlayers = (new TeamStrengthParameters)->topPlayers;

        return PlayerMarket::query()
            ->join('players', 'players.id', '=', 'player_markets.player_id')
            ->whereDate('player_markets.date', $referenceDate)
            ->whereIn('players.team_id', $teamIds)
            ->orderByDesc('player_markets.value')
            ->toBase()
            ->get(['players.team_id as team_id', 'player_markets.value as value'])
            ->groupBy('team_id')
            ->map(fn (Collection $rows): float => log($rows->take($topPlayers)->sum(fn (object $row): int => (int) $row->value) + 1))
            ->all();
    }

    /**
     * The latest day (Y-m-d) up to `$at` with any published market values,
     * null when there is none — the same rule as `MaxBidCalculator::referenceDate()`.
     */
    private function referenceDate(CarbonInterface $at): ?string
    {
        $latest = PlayerMarket::query()->whereDate('date', '<=', $at)->max('date');

        return $latest === null ? null : substr((string) $latest, 0, 10);
    }

    /**
     * Raw per-team sums over the season's finished fixtures dated before
     * `$at`, from one fixtures query and one fixture_lineups query (grouped
     * in PHP), never one query per team or per team-match.
     *
     * @return array<int, array{matches: int, goals_for: int, goals_against: int, shots_for: int, shots_against: int, failed_to_score: int, key_passes_for: int, key_passes_against: int, key_passes_matches: int}>
     */
    private function matchAggregates(Season $season, CarbonInterface $at): array
    {
        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where('date', '<', $at)
            ->get(['id', 'team_local_id', 'team_guest_id', 'local_score', 'guest_score', 'local_key_passes', 'guest_key_passes']);

        if ($fixtures->isEmpty()) {
            return [];
        }

        $shotsOnTarget = FixtureLineup::query()
            ->whereIn('fixture_id', $fixtures->pluck('id'))
            ->toBase()
            ->get(['fixture_id', 'team_id', 'stats'])
            ->groupBy(fn (object $lineup): string => "{$lineup->fixture_id}:{$lineup->team_id}")
            ->map(fn (Collection $lineups): int => (int) $lineups->sum(fn (object $lineup): int => self::statValue(self::decodeStats($lineup->stats), 'shotsOnTarget')));

        $aggregates = [];

        foreach ($fixtures as $fixture) {
            $localShots = $shotsOnTarget->get("{$fixture->id}:{$fixture->team_local_id}", 0);
            $guestShots = $shotsOnTarget->get("{$fixture->id}:{$fixture->team_guest_id}", 0);
            $keyPassesKnown = $fixture->local_key_passes !== null && $fixture->guest_key_passes !== null;

            self::accumulate(
                $aggregates,
                $fixture->team_local_id,
                $fixture->local_score ?? 0,
                $fixture->guest_score ?? 0,
                $localShots,
                $guestShots,
                $keyPassesKnown,
                $fixture->local_key_passes,
                $fixture->guest_key_passes,
            );
            self::accumulate(
                $aggregates,
                $fixture->team_guest_id,
                $fixture->guest_score ?? 0,
                $fixture->local_score ?? 0,
                $guestShots,
                $localShots,
                $keyPassesKnown,
                $fixture->guest_key_passes,
                $fixture->local_key_passes,
            );
        }

        return $aggregates;
    }

    /**
     * @param  array<int, array{matches: int, goals_for: int, goals_against: int, shots_for: int, shots_against: int, failed_to_score: int, key_passes_for: int, key_passes_against: int, key_passes_matches: int}>  $aggregates
     */
    private static function accumulate(
        array &$aggregates,
        int $teamId,
        int $goalsFor,
        int $goalsAgainst,
        int $shotsFor,
        int $shotsAgainst,
        bool $keyPassesKnown,
        ?int $keyPassesFor,
        ?int $keyPassesAgainst,
    ): void {
        $aggregates[$teamId] ??= [
            'matches' => 0,
            'goals_for' => 0,
            'goals_against' => 0,
            'shots_for' => 0,
            'shots_against' => 0,
            'failed_to_score' => 0,
            'key_passes_for' => 0,
            'key_passes_against' => 0,
            'key_passes_matches' => 0,
        ];

        $aggregates[$teamId]['matches']++;
        $aggregates[$teamId]['goals_for'] += $goalsFor;
        $aggregates[$teamId]['goals_against'] += $goalsAgainst;
        $aggregates[$teamId]['shots_for'] += $shotsFor;
        $aggregates[$teamId]['shots_against'] += $shotsAgainst;
        $aggregates[$teamId]['failed_to_score'] += $goalsFor === 0 ? 1 : 0;

        if ($keyPassesKnown) {
            $aggregates[$teamId]['key_passes_for'] += $keyPassesFor;
            $aggregates[$teamId]['key_passes_against'] += $keyPassesAgainst;
            $aggregates[$teamId]['key_passes_matches']++;
        }
    }

    /**
     * @param  array{matches: int, goals_for: int, goals_against: int, shots_for: int, shots_against: int, failed_to_score: int, key_passes_for: int, key_passes_against: int, key_passes_matches: int}|null  $aggregate
     */
    private static function teamInputs(int $teamId, ?float $logValue, ?array $aggregate): TeamStrengthInputs
    {
        $matches = $aggregate['matches'] ?? 0;

        if ($matches === 0) {
            return new TeamStrengthInputs($teamId, 0, $logValue, null, null, null, null, null, null, null, null, null);
        }

        $keyPassesMatches = $aggregate['key_passes_matches'];

        return new TeamStrengthInputs(
            teamId: $teamId,
            matches: $matches,
            logValue: $logValue,
            goalDifference: ($aggregate['goals_for'] - $aggregate['goals_against']) / $matches,
            shotsOnTargetDifference: ($aggregate['shots_for'] - $aggregate['shots_against']) / $matches,
            keyPassesFor: $keyPassesMatches > 0 ? $aggregate['key_passes_for'] / $keyPassesMatches : null,
            goalsFor: $aggregate['goals_for'] / $matches,
            shotsOnTargetFor: $aggregate['shots_for'] / $matches,
            failedToScoreRate: $aggregate['failed_to_score'] / $matches,
            goalsAgainst: $aggregate['goals_against'] / $matches,
            shotsOnTargetAgainst: $aggregate['shots_against'] / $matches,
            keyPassesAgainst: $keyPassesMatches > 0 ? $aggregate['key_passes_against'] / $keyPassesMatches : null,
        );
    }

    /**
     * A raw `fixture_lineups.stats` JSON column, read without hydrating the model.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function decodeStats(mixed $stats): array
    {
        $decoded = is_string($stats) ? json_decode($stats, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Sum of a named stat across `fixture_lineups.stats` rows, duplicating
     * `SummarizesFixtureStats::statValue()`'s reading of the `{name, value}`
     * list rather than depending on an Http\Controllers concern from a
     * service.
     *
     * @param  array<int, array<string, mixed>>  $stats
     */
    private static function statValue(array $stats, string $name): int
    {
        foreach ($stats as $stat) {
            if (($stat['name'] ?? null) === $name) {
                return (int) ($stat['value'] ?? 0);
            }
        }

        return 0;
    }

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

            $teamValueZ = $valueZ[$teamId] ?? 0.0;
            $general = (1 - $weight) * $teamValueZ + $weight * $performanceZ;
            $offensiveThreat = (1 - $weight) * $teamValueZ
                + $weight * ((1 - $parameters->specificShare) * $performanceZ + $parameters->specificShare * $attackZ);
            $defensiveSolidity = (1 - $weight) * $teamValueZ
                + $weight * ((1 - $parameters->specificShare) * $performanceZ + $parameters->specificShare * $defenseZ);

            $ratings[$teamId] = new TeamStrengthRating(
                teamId: $teamId,
                matches: $input->matches,
                valueZ: $teamValueZ,
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
