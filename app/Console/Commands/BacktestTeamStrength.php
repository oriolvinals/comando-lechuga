<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\Season;
use App\Services\LeagueStandings;
use App\Services\TeamStrength;
use App\Services\TeamStrengthInputs;
use App\Services\TeamStrengthParameters;
use App\Services\TeamStrengthRating;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Walk-forward check of the team strength model (spec §5): for every finished
 * match where both teams already had at least two previous matches, the ease of
 * each side (strength of the rival at that date, no absences, plus the home
 * bonus) is correlated, Spearman, with what the side then did. Reads only.
 *
 * @phpstan-type Observation array{date: string, team_id: int, rival_id: int, home: bool, position_ease: float, targets: array<string, float|null>}
 */
#[Signature('season:backtest-team-strength {--grid : Also sweep shrinkK, homeBonus and specificShare in memory and show the best combination per target (writes nothing)}')]
#[Description('Replay the team strength model walk-forward and report how well it explains match results (writes nothing)')]
class BacktestTeamStrength extends Command
{
    /** A side needs at least this many earlier finished matches, and so does its rival. */
    private const int MINIMUM_PREVIOUS_MATCHES = 2;

    /** @var array<string, string> */
    private const array TARGETS = [
        'points' => 'Puntos',
        'goal_difference' => 'Dif. de goles',
        'goals_for' => 'Goles a favor',
        'clean_sheet' => 'Portería a cero',
        'fantasy_attack' => 'Fantasy medios y delanteros',
        'fantasy_defense' => 'Fantasy porteros y defensas',
        'fantasy_all' => 'Fantasy todos',
    ];

    /** @var list<int> */
    private const array GRID_SHRINK_K = [4, 8, 16];

    /** @var list<float> */
    private const array GRID_HOME_BONUS = [0.2, 0.4, 0.6];

    /** @var list<float> */
    private const array GRID_SPECIFIC_SHARE = [0.2, 0.3, 0.5];

    /**
     * The (variant, target) pairs the grid reads; `defense` is read on the
     * Fantasy points of goalkeepers and defenders, not on clean sheets.
     *
     * @var list<array{0: DifficultyVariant, 1: string}>
     */
    private const array GRID_PAIRS = [
        [DifficultyVariant::General, 'points'],
        [DifficultyVariant::General, 'goal_difference'],
        [DifficultyVariant::General, 'fantasy_all'],
        [DifficultyVariant::Attack, 'goals_for'],
        [DifficultyVariant::Attack, 'fantasy_attack'],
        [DifficultyVariant::Defense, 'fantasy_defense'],
    ];

    public function handle(TeamStrength $strength, LeagueStandings $standings): int
    {
        $season = Season::current();

        $fixtures = $this->qualifyingFixtures($season);

        if ($fixtures === []) {
            $this->info('No hay partidos terminados en los que los dos equipos lleven al menos '.self::MINIMUM_PREVIOUS_MATCHES.' partidos previos.');

            return self::SUCCESS;
        }

        /** @var array<string, list<TeamStrengthInputs>> $inputsByDate */
        $inputsByDate = [];
        $observations = $this->observations($season, $fixtures, $strength, $standings, $inputsByDate);

        $this->info(sprintf(
            '%d observaciones (%d partidos terminados con al menos %d previos en ambos equipos), temporada %s. Facilidad = −fuerza del rival ± local; ρ positiva = acierta.',
            count($observations),
            count($fixtures),
            self::MINIMUM_PREVIOUS_MATCHES,
            $season->name,
        ));

        $defaults = new TeamStrengthParameters;
        $easeByVariant = $this->easeByVariant($observations, $inputsByDate, $defaults);

        $rows = [];

        foreach (DifficultyVariant::cases() as $variant) {
            $rows[] = [$variant->value, ...$this->correlationRow($easeByVariant[$variant->value], $observations)];
        }

        $rows[] = ['posición en la tabla', ...$this->correlationRow(array_column($observations, 'position_ease'), $observations)];
        $rows[] = ['n', ...array_map(
            fn (string $target): string => (string) count(array_filter(
                $observations,
                fn (array $observation): bool => $observation['targets'][$target] !== null,
            )),
            array_keys(self::TARGETS),
        )];

        $this->table(['ρ de Spearman', ...array_values(self::TARGETS)], $rows);

        if ($this->option('grid')) {
            $this->gridSearch($observations, $inputsByDate, $defaults);
        }

        return self::SUCCESS;
    }

    /**
     * The season's finished fixtures, oldest first, whose two teams both
     * already had enough finished matches before it.
     *
     * @return list<Fixture>
     */
    private function qualifyingFixtures(Season $season): array
    {
        $played = [];
        $qualifying = [];

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'season_id', 'date', 'team_local_id', 'team_guest_id', 'local_score', 'guest_score']);

        foreach ($fixtures as $fixture) {
            if (
                ($played[$fixture->team_local_id] ?? 0) >= self::MINIMUM_PREVIOUS_MATCHES
                && ($played[$fixture->team_guest_id] ?? 0) >= self::MINIMUM_PREVIOUS_MATCHES
            ) {
                $qualifying[] = $fixture;
            }

            $played[$fixture->team_local_id] = ($played[$fixture->team_local_id] ?? 0) + 1;
            $played[$fixture->team_guest_id] = ($played[$fixture->team_guest_id] ?? 0) + 1;
        }

        return $qualifying;
    }

    /**
     * Two observations per fixture (one per side). Inputs and standings are
     * gathered once per distinct date and kept in `$inputsByDate` so the grid
     * can replay the maths with other parameters without touching the database.
     *
     * @param  list<Fixture>  $fixtures
     * @param  array<string, list<TeamStrengthInputs>>  $inputsByDate
     * @return list<Observation>
     */
    private function observations(Season $season, array $fixtures, TeamStrength $strength, LeagueStandings $standings, array &$inputsByDate): array
    {
        $fantasy = $this->fantasyMeans($season, $fixtures);
        $observations = [];
        $positionsByDate = [];

        foreach ($fixtures as $fixture) {
            $date = $fixture->date->format('Y-m-d H:i:s');

            if (!isset($inputsByDate[$date])) {
                $inputsByDate[$date] = $strength->inputsAt($season, $fixture->date);
                $positionsByDate[$date] = $standings->positions($season, $fixture->date->subSecond());
            }

            $teamIds = array_map(fn (TeamStrengthInputs $inputs): int => $inputs->teamId, $inputsByDate[$date]);

            if (!in_array($fixture->team_local_id, $teamIds, true) || !in_array($fixture->team_guest_id, $teamIds, true)) {
                continue;
            }

            $teamCount = count($positionsByDate[$date]);
            $localScore = $fixture->local_score ?? 0;
            $guestScore = $fixture->guest_score ?? 0;

            foreach ([[true, $fixture->team_local_id, $fixture->team_guest_id, $localScore, $guestScore], [false, $fixture->team_guest_id, $fixture->team_local_id, $guestScore, $localScore]] as [$home, $teamId, $rivalId, $for, $against]) {
                $rivalPosition = $positionsByDate[$date][$rivalId] ?? null;

                $observations[] = [
                    'date' => $date,
                    'team_id' => $teamId,
                    'rival_id' => $rivalId,
                    'home' => $home,
                    'position_ease' => $rivalPosition === null ? 0.0 : LeagueStandings::difficulty($rivalPosition, $teamCount),
                    'targets' => [
                        'points' => (float) ($for > $against ? 3 : ($for === $against ? 1 : 0)),
                        'goal_difference' => (float) ($for - $against),
                        'goals_for' => (float) $for,
                        'clean_sheet' => $against === 0 ? 1.0 : 0.0,
                        'fantasy_attack' => $fantasy["{$fixture->id}:{$teamId}"]['attack'] ?? null,
                        'fantasy_defense' => $fantasy["{$fixture->id}:{$teamId}"]['defense'] ?? null,
                        'fantasy_all' => $fantasy["{$fixture->id}:{$teamId}"]['all'] ?? null,
                    ],
                ];
            }
        }

        return $observations;
    }

    /**
     * Mean Fantasy points of each side's starters (goalkeepers and defenders,
     * midfielders and strikers, and all of them), positions from the season's
     * `player_seasons`, keyed `fixtureId:teamId`.
     *
     * @param  list<Fixture>  $fixtures
     * @return array<string, array{attack?: float, defense?: float, all?: float}>
     */
    private function fantasyMeans(Season $season, array $fixtures): array
    {
        $positions = DB::table('player_seasons')
            ->where('season_id', $season->id)
            ->pluck('position', 'player_id');

        $lists = [];

        foreach (array_chunk(array_map(fn (Fixture $fixture): int => $fixture->id, $fixtures), 500) as $ids) {
            $lineups = DB::table('fixture_lineups')
                ->whereIn('fixture_id', $ids)
                ->where('starter', true)
                ->whereNotNull('player_id')
                ->whereNotNull('fantasy_points')
                ->get(['fixture_id', 'team_id', 'player_id', 'fantasy_points']);

            foreach ($lineups as $lineup) {
                $key = "{$lineup->fixture_id}:{$lineup->team_id}";
                $points = (float) $lineup->fantasy_points;
                $position = PlayerPosition::tryFrom((string) ($positions[$lineup->player_id] ?? ''));

                $lists[$key]['all'][] = $points;

                if ($position === PlayerPosition::Goalkeeper || $position === PlayerPosition::Defender) {
                    $lists[$key]['defense'][] = $points;
                } elseif ($position === PlayerPosition::Midfield || $position === PlayerPosition::Striker) {
                    $lists[$key]['attack'][] = $points;
                }
            }
        }

        return array_map(
            fn (array $groups): array => array_map(fn (array $points): float => array_sum($points) / count($points), $groups),
            $lists,
        );
    }

    /**
     * Each variant's ease per observation: −rating(rival) + homeBonus·(±1).
     *
     * @param  list<Observation>  $observations
     * @param  array<string, list<TeamStrengthInputs>>  $inputsByDate
     * @return array<string, list<float>> keyed by variant value
     */
    private function easeByVariant(array $observations, array $inputsByDate, TeamStrengthParameters $parameters): array
    {
        /** @var array<string, array<int, TeamStrengthRating>> $ratingsByDate */
        $ratingsByDate = array_map(
            fn (array $inputs): array => TeamStrength::fromInputs($inputs, $parameters),
            $inputsByDate,
        );

        $ease = [];

        foreach (DifficultyVariant::cases() as $variant) {
            $ease[$variant->value] = array_map(
                fn (array $observation): float => -$ratingsByDate[$observation['date']][$observation['rival_id']]->for($variant)
                    + $parameters->homeBonus * ($observation['home'] ? 1 : -1),
                $observations,
            );
        }

        return $ease;
    }

    /**
     * @param  list<float>  $ease
     * @param  list<Observation>  $observations
     * @return list<string> the ρ against each target, formatted
     */
    private function correlationRow(array $ease, array $observations): array
    {
        return array_map(
            fn (string $target): string => $this->rho($this->correlation($ease, $observations, $target)),
            array_keys(self::TARGETS),
        );
    }

    /**
     * @param  list<float>  $ease
     * @param  list<Observation>  $observations
     */
    private function correlation(array $ease, array $observations, string $target): ?float
    {
        return self::spearman($ease, array_map(fn (array $observation): ?float => $observation['targets'][$target], $observations));
    }

    /**
     * Sweeps shrinkK × homeBonus × specificShare, replaying only the maths on
     * the inputs already gathered, and prints the best combination for each
     * (variant, target) pair. Ties keep the defaults, then the first found.
     *
     * @param  list<Observation>  $observations
     * @param  array<string, list<TeamStrengthInputs>>  $inputsByDate
     */
    private function gridSearch(array $observations, array $inputsByDate, TeamStrengthParameters $defaults): void
    {
        $defaultEase = $this->easeByVariant($observations, $inputsByDate, $defaults);

        /** @var array<int, array{rho: float|null, parameters: TeamStrengthParameters}> $best */
        $best = [];

        foreach (self::GRID_PAIRS as $index => [$variant, $target]) {
            $best[$index] = ['rho' => $this->correlation($defaultEase[$variant->value], $observations, $target), 'parameters' => $defaults];
        }

        $defaultRho = array_map(fn (array $entry): ?float => $entry['rho'], $best);

        foreach (self::GRID_SHRINK_K as $shrinkK) {
            foreach (self::GRID_HOME_BONUS as $homeBonus) {
                foreach (self::GRID_SPECIFIC_SHARE as $specificShare) {
                    $parameters = new TeamStrengthParameters(shrinkK: $shrinkK, homeBonus: $homeBonus, specificShare: $specificShare);
                    $ease = $this->easeByVariant($observations, $inputsByDate, $parameters);

                    foreach (self::GRID_PAIRS as $index => [$variant, $target]) {
                        $rho = $this->correlation($ease[$variant->value], $observations, $target);

                        if ($rho !== null && ($best[$index]['rho'] === null || $rho > $best[$index]['rho'] + 1e-9)) {
                            $best[$index] = ['rho' => $rho, 'parameters' => $parameters];
                        }
                    }
                }
            }
        }

        $rows = [];

        foreach (self::GRID_PAIRS as $index => [$variant, $target]) {
            $parameters = $best[$index]['parameters'];
            $rows[] = [
                $variant->value,
                self::TARGETS[$target],
                (string) $parameters->shrinkK,
                number_format($parameters->homeBonus, 1, ',', '.'),
                number_format($parameters->specificShare, 1, ',', '.'),
                $this->rho($best[$index]['rho']),
                $this->rho($defaultRho[$index]),
            ];
        }

        $this->newLine();
        $this->info(sprintf(
            'Mejor combinación por objetivo (%d combinaciones; defense se lee sobre los puntos Fantasy de porteros y defensas; specificShare no afecta a general). No se aplica nada.',
            count(self::GRID_SHRINK_K) * count(self::GRID_HOME_BONUS) * count(self::GRID_SPECIFIC_SHARE),
        ));
        $this->table(['Variante', 'Objetivo', 'shrinkK', 'homeBonus', 'specificShare', 'ρ mejor', 'ρ por defecto (8 / 0,4 / 0,3)'], $rows);
    }

    private function rho(?float $rho): string
    {
        return $rho === null ? '—' : number_format($rho, 2, ',', '.');
    }

    /**
     * Spearman's ρ: Pearson over the ranks, tied values sharing their average
     * rank. Only the pairs where both values exist count; null when fewer
     * than two remain or either side has no variation.
     *
     * @param  list<float|null>  $x
     * @param  list<float|null>  $y
     */
    private static function spearman(array $x, array $y): ?float
    {
        $xs = [];
        $ys = [];

        foreach ($x as $index => $value) {
            if ($value !== null && ($y[$index] ?? null) !== null) {
                $xs[] = $value;
                $ys[] = $y[$index];
            }
        }

        if (count($xs) < 2) {
            return null;
        }

        $xRanks = self::averageRanks($xs);
        $yRanks = self::averageRanks($ys);
        $count = count($xRanks);
        $xMean = array_sum($xRanks) / $count;
        $yMean = array_sum($yRanks) / $count;
        $covariance = 0.0;
        $xVariance = 0.0;
        $yVariance = 0.0;

        foreach ($xRanks as $index => $xRank) {
            $covariance += ($xRank - $xMean) * ($yRanks[$index] - $yMean);
            $xVariance += ($xRank - $xMean) ** 2;
            $yVariance += ($yRanks[$index] - $yMean) ** 2;
        }

        return $xVariance > 0.0 && $yVariance > 0.0 ? $covariance / sqrt($xVariance * $yVariance) : null;
    }

    /**
     * 1-based ranks, ascending, with ties sharing the mean of their positions.
     *
     * @param  list<float>  $values
     * @return list<float>
     */
    private static function averageRanks(array $values): array
    {
        $order = array_keys($values);
        usort($order, fn (int $a, int $b): int => $values[$a] <=> $values[$b]);

        $ranks = array_fill(0, count($values), 0.0);
        $start = 0;

        while ($start < count($order)) {
            $end = $start;

            while ($end + 1 < count($order) && $values[$order[$end + 1]] === $values[$order[$start]]) {
                $end++;
            }

            for ($position = $start; $position <= $end; $position++) {
                $ranks[$order[$position]] = ($start + $end) / 2 + 1.0;
            }

            $start = $end + 1;
        }

        return array_values($ranks);
    }
}
