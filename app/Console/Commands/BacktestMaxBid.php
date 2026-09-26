<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BadScoreRule;
use App\Enums\MarketTrend;
use App\Enums\MaxBidStatus;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\MaxBidCalculator;
use App\Services\MaxBidEstimate;
use App\Services\MaxBidInputs;
use App\Services\MaxBidParameters;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('season:backtest-max-bid {--from= : First reference date (Y-m-d)} {--to= : Last reference date (Y-m-d)} {--player= : Nickname of one player to replay day by day} {--grid : Grid-search the model parameters in memory instead (writes nothing)} {--grid-decay : Grid-search only both decays below 0.80 around the chosen calibration (pass 3; writes nothing)} {--grid-streak : Grid-search only the streak exception to the bad-score cap around the defaults (pass 4; writes nothing)} {--phase= : With a grid, replay only the days of one phase: matchweek or break} {--control= : With a grid, nickname of a player whose results under the top combinations are shown as a qualitative check} {--control-from= : First date (Y-m-d) of the control check} {--control-to= : Last date (Y-m-d) of the control check}')]
#[Description('Replay the max bid model over the market history and report how it would have done')]
class BacktestMaxBid extends Command
{
    /** Phases `--phase` accepts. */
    private const array PHASES = ['matchweek', 'break'];

    /** A grid combination must call at least this share of the defaults' profitable count, so calling almost nothing profitable can't win. */
    private const float MINIMUM_PROFITABLE_SHARE = 0.2;

    private const int GRID_TOP = 10;

    /** @var list<float> */
    private const array GRID_DECAYS = [0.8, 0.85, 0.9, 0.95];

    /** @var list<float> Pass 3: below pass 1's lower edge. */
    private const array GRID_LOW_DECAYS = [0.65, 0.7, 0.75, 0.8];

    /** @var list<float> Pass 4: the streak exception's minimum own momentum pace (per day). */
    private const array GRID_STREAK_PACES = [0.03, 0.05, 0.08];

    /** @var list<int> Pass 4: the streak exception's minimum team points in its last three matches. */
    private const array GRID_STREAK_TEAM_POINTS = [4, 6, 7];

    /** @var list<float> */
    private const array GRID_SPORT_DAILY_RATES = [0.005, 0.01, 0.02];

    /** @var list<float> */
    private const array GRID_PROXIMITY_HALF_LIVES = [4.0, 7.0, 10.0];

    /** @var list<int> */
    private const array GRID_BENCHES_BEFORE_UNPROFITABLE = [0, 1, 2];

    /** @var list<float> */
    private const array GRID_BENCH_INCREMENT_FACTORS = [0.0, 0.25, 0.5, 0.7, 1.0];

    public function handle(MaxBidCalculator $calculator): int
    {
        $gridPass = match (true) {
            (bool) $this->option('grid-streak') => 'streak',
            (bool) $this->option('grid-decay') => 'decay',
            (bool) $this->option('grid') => 'broad',
            default => null,
        };
        $grid = $gridPass !== null;
        $phaseOption = $this->option('phase');
        $phase = is_string($phaseOption) ? $phaseOption : null;

        if ($phase !== null && (!$grid || !in_array($phase, self::PHASES, true))) {
            $this->error("La fase «{$phase}» no es válida: usa --phase=matchweek o --phase=break, junto con --grid, --grid-decay o --grid-streak.");

            return self::FAILURE;
        }

        $season = Season::current();
        $latest = PlayerMarket::query()->max('date');

        if ($latest === null) {
            $this->error('No hay histórico de mercado que reproducir.');

            return self::FAILURE;
        }

        $toOption = $this->option('to');
        $to = is_string($toOption)
            ? CarbonImmutable::parse($toOption)
            : CarbonImmutable::parse((string) $latest)->subDays(MaxBidCalculator::LOCK_DAYS);

        $fromOption = $this->option('from');
        $from = is_string($fromOption)
            ? CarbonImmutable::parse($fromOption)
            : CarbonImmutable::parse($season->start_date)->addDays(MaxBidCalculator::MOMENTUM_DAYS);

        $playerOption = $this->option('player');
        $nickname = is_string($playerOption) ? $playerOption : null;

        if ($nickname !== null) {
            $players = Player::query()->where('nickname', $nickname)->with('team')->get();

            if ($players->isEmpty()) {
                $this->error("No existe ningún jugador con el apodo «{$nickname}».");

                return self::FAILURE;
            }

            if ($players->count() > 1) {
                $matches = $players
                    ->map(fn (Player $player): string => sprintf('#%d (%s)', $player->id, $player->team->main_name))
                    ->implode(', ');
                $this->error("Hay {$players->count()} jugadores con el apodo «{$nickname}»: {$matches}.");

                return self::FAILURE;
            }
        } else {
            $players = Player::query()
                ->whereIn('team_id', $season->teams()->select('teams.id'))
                ->whereIn('status', [PlayerStatus::Ok, PlayerStatus::Doubtful])
                ->get();
        }

        // Read as plain stdClass rows via a cursor (never hydrating a PlayerMarket model or a Carbon
        // date), so this stays a handful of compact scalars per row instead of ~35k Eloquent models —
        // the dominant cost of the whole replay before this fix (see task-6-report.md, fix round 2).
        /** @var array<int, array<string, int>> $valuesByPlayer player id → date → value, oldest first */
        $valuesByPlayer = [];

        foreach (
            PlayerMarket::query()
                ->whereIn('player_id', $players->pluck('id'))
                ->orderBy('date')
                ->select(['player_id', 'date', 'value'])
                ->toBase()
                ->cursor() as $row
        ) {
            $valuesByPlayer[(int) $row->player_id][substr((string) $row->date, 0, 10)] = (int) $row->value;
        }

        if ($gridPass !== null) {
            $controlOption = $this->option('control');
            $control = null;

            if (is_string($controlOption)) {
                $controls = $players->where('nickname', $controlOption);

                if ($controls->count() !== 1) {
                    $this->error("El jugador de control «{$controlOption}» no está (o no es único) entre los jugadores reproducidos.");

                    return self::FAILURE;
                }

                $controlFrom = $this->option('control-from');
                $controlTo = $this->option('control-to');
                $control = [
                    'player' => $controls->firstOrFail(),
                    'from' => is_string($controlFrom) ? CarbonImmutable::parse($controlFrom)->toDateString() : $from->toDateString(),
                    'to' => is_string($controlTo) ? CarbonImmutable::parse($controlTo)->toDateString() : $to->toDateString(),
                ];
            }

            return $this->gridSearch($calculator, $season, $players, $valuesByPlayer, $from, $to, $phase, $gridPass, $control);
        }

        /** @var array<string, array{count: int, profitableCount: int, profitableProbabilitySum: float, unprofitableCount: int, unprofitableRoseCount: int, errors: list<float>}> $groups */
        $groups = [];

        /** @var list<array{date: string, value: int, status: MaxBidStatus, bid: int|null, projectedDay14: int, actualDay14: int, probability: float|null, rose: bool}> $playerRows */
        $playerRows = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            foreach ($players as $player) {
                $values = $valuesByPlayer[$player->id] ?? [];
                $actual = $this->actualValues($values, $day);

                if ($actual === null) {
                    continue;
                }

                $estimate = $calculator->estimate($player, $season, $day);

                if (!in_array($estimate->status, [MaxBidStatus::Profitable, MaxBidStatus::Unprofitable], true)) {
                    continue;
                }

                $trendCase = $this->trend($values, $day);
                $trend = $trendCase === null ? 'sin tendencia' : $trendCase->value;
                $nextMatchDays = $estimate->upcomingRivals[0]['days_until'] ?? null;
                $phaseLabel = $nextMatchDays === null || $nextMatchDays > MaxBidInputs::MATCHWEEK_MAX_DAYS ? 'parón' : 'semana de partido';
                ['error' => $error, 'probability' => $probability, 'rose' => $rose] = $this->outcome($estimate, $actual);

                foreach (['Total', "tendencia: {$trend}", "fase: {$phaseLabel}"] as $group) {
                    $this->addToGroup($groups, $group, $estimate->status, $error, $probability, $rose);
                }

                if ($nickname !== null) {
                    $playerRows[] = [
                        'date' => $day->toDateString(),
                        'value' => $estimate->value,
                        'status' => $estimate->status,
                        'bid' => $estimate->bid,
                        'projectedDay14' => $estimate->projection[MaxBidCalculator::LOCK_DAYS],
                        'actualDay14' => $actual[MaxBidCalculator::LOCK_DAYS],
                        'probability' => $probability,
                        'rose' => $rose,
                    ];
                }
            }
        }

        $this->table(
            ['Grupo', 'Estimaciones', 'Rentables', 'Prob. real media (obj. 75 %)', 'Sin rentab.', 'Falsos negativos', 'Error proy. medio', 'Error proy. mediana'],
            $this->rows($groups),
        );

        if ($nickname !== null) {
            $this->table(
                ['Fecha', 'Valor', 'Estado', 'Puja', 'Proy. día 14', 'Real día 14', 'Prob. real / ¿subió?'],
                $this->playerRows($playerRows),
            );
        }

        return self::SUCCESS;
    }

    /**
     * Gathers each (player, day)'s inputs once, then replays the formula in
     * memory for every parameter combination: passes 1 and 2 (`--grid`), pass
     * 3 (`--grid-decay`) or pass 4 (`--grid-streak`).
     *
     * @param  Collection<int, Player>  $players
     * @param  array<int, array<string, int>>  $valuesByPlayer
     * @param  'broad'|'decay'|'streak'  $gridPass
     * @param  array{player: Player, from: string, to: string}|null  $control
     */
    private function gridSearch(MaxBidCalculator $calculator, Season $season, Collection $players, array $valuesByPlayer, CarbonImmutable $from, CarbonImmutable $to, ?string $phase, string $gridPass, ?array $control): int
    {
        $startedAt = hrtime(true);

        /** @var list<array{0: MaxBidInputs, 1: list<int>, 2: MarketTrend|null}> $records */
        $records = [];

        /** @var list<int> $controlRecords indexes in `$records` of the control player's days */
        $controlRecords = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            foreach ($players as $player) {
                $values = $valuesByPlayer[$player->id] ?? [];
                $actual = $this->actualValues($values, $day);

                if ($actual === null) {
                    continue;
                }

                $inputs = $calculator->gatherInputs($player, $season, $day);

                if ($inputs->presetStatus !== null || ($phase === 'matchweek' && $inputs->isBreak()) || ($phase === 'break' && !$inputs->isBreak())) {
                    continue;
                }

                $records[] = [$inputs, $actual, $this->trend($values, $day)];

                if ($control !== null && $player->id === $control['player']->id && $day->toDateString() >= $control['from'] && $day->toDateString() <= $control['to']) {
                    $controlRecords[] = array_key_last($records);
                }
            }
        }

        $phaseLabel = match ($phase) {
            'matchweek' => 'solo semana de partido',
            'break' => 'solo parón',
            default => 'todas las fases',
        };

        if ($records === []) {
            $this->info("No hay estimaciones que reproducir ({$phaseLabel}).");

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d estimaciones (%s, %s a %s) reunidas en %.1f s.',
            count($records),
            $phaseLabel,
            $from->toDateString(),
            $to->toDateString(),
            (hrtime(true) - $startedAt) / 1e9,
        ));

        $defaults = new MaxBidParameters;
        $defaultsMetrics = $this->metrics($this->replay($records, $defaults, false)['Total']);
        $minimumProfitable = (int) ceil(self::MINIMUM_PROFITABLE_SHARE * $defaultsMetrics['profitableCount']);

        [$winnerLabel, $ranking] = match ($gridPass) {
            'decay' => ['pasada 3', $this->decayPass($records, $defaults, $defaultsMetrics, $minimumProfitable)],
            'streak' => ['pasada 4', $this->streakPass($records, $defaults, $defaultsMetrics, $minimumProfitable)],
            'broad' => ['pasada 2', $this->broadPasses($records, $defaults, $defaultsMetrics, $minimumProfitable)],
        };

        $this->newLine();
        $this->info("Desglose de la ganadora de la {$winnerLabel}");
        $this->table(
            ['Grupo', 'Estimaciones', 'Rentables', 'Prob. real media (obj. 75 %)', 'Sin rentab.', 'Falsos negativos', 'Error proy. medio', 'Error proy. mediana'],
            $this->rows($this->replay($records, $ranking[0]['parameters'] ?? $defaults, true)),
        );

        if ($control !== null) {
            $this->printControl($records, $controlRecords, $control, $ranking, $defaults);
        }

        $this->info(sprintf(
            'Tiempo total: %.1f s · memoria pico: %.1f MB (límite %s).',
            (hrtime(true) - $startedAt) / 1e9,
            memory_get_peak_usage(true) / 1024 / 1024,
            (string) ini_get('memory_limit'),
        ));

        return self::SUCCESS;
    }

    /**
     * Pass 3: both decays below pass 1's lower edge, with every other
     * parameter fixed at the chosen calibration (option B). Returns its ranking.
     *
     * @param  list<array{0: MaxBidInputs, 1: list<int>, 2: MarketTrend|null}>  $records
     * @param  array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}  $defaultsMetrics
     * @return list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>
     */
    private function decayPass(array $records, MaxBidParameters $defaults, array $defaultsMetrics, int $minimumProfitable): array
    {
        $combinations = [];

        foreach (self::GRID_LOW_DECAYS as $decayBreak) {
            foreach (self::GRID_LOW_DECAYS as $decayMatchweek) {
                $parameters = new MaxBidParameters(
                    incrementDecayBreak: $decayBreak,
                    incrementDecayMatchweek: $decayMatchweek,
                    sportDailyRate: 0.005,
                    proximityHalfLifeDays: 7.0,
                    benchIncrementFactor: 0.25,
                    benchesBeforeUnprofitable: 1,
                    badScoreRule: BadScoreRule::AtMostTwo,
                );
                $combinations[] = ['parameters' => $parameters, 'metrics' => $this->metrics($this->replay($records, $parameters, false)['Total'])];
            }
        }

        $ranking = $this->rank($combinations, $minimumProfitable);
        $this->printPass('Pasada 3: decay parón × decay jornada por debajo de 0,80, con ritmo 0,005, vida media 7 d, factor banquillo 0,25, 1 banquillo y mala nota ≤ 2', $combinations, $ranking, $defaults, $defaultsMetrics, $minimumProfitable);

        return $ranking;
    }

    /**
     * Pass 4: the streak exception to the bad-score cap, every other
     * parameter at its default. Off (a null pace) is one combination, since
     * the team-points threshold is then irrelevant. Returns its ranking.
     *
     * @param  list<array{0: MaxBidInputs, 1: list<int>, 2: MarketTrend|null}>  $records
     * @param  array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}  $defaultsMetrics
     * @return list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>
     */
    private function streakPass(array $records, MaxBidParameters $defaults, array $defaultsMetrics, int $minimumProfitable): array
    {
        $candidates = [new MaxBidParameters(streakExceptionPace: null)];

        foreach (self::GRID_STREAK_PACES as $pace) {
            foreach (self::GRID_STREAK_TEAM_POINTS as $teamPoints) {
                $candidates[] = new MaxBidParameters(streakExceptionPace: $pace, streakExceptionTeamPoints: $teamPoints);
            }
        }

        $combinations = array_map(
            fn (MaxBidParameters $parameters): array => ['parameters' => $parameters, 'metrics' => $this->metrics($this->replay($records, $parameters, false)['Total'])],
            $candidates,
        );

        $ranking = $this->rank($combinations, $minimumProfitable);
        $this->printPass('Pasada 4: excepción de racha a la mala nota (ritmo propio × puntos del equipo en 3 partidos; titular en los 3), el resto por defecto', $combinations, $ranking, $defaults, $defaultsMetrics, $minimumProfitable);

        return $ranking;
    }

    /**
     * Pass 1 sweeps the continuous parameters with the rules at their
     * defaults; pass 2 fixes pass 1's best and sweeps the bench and bad-score
     * rules. Returns pass 2's ranking.
     *
     * @param  list<array{0: MaxBidInputs, 1: list<int>, 2: MarketTrend|null}>  $records
     * @param  array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}  $defaultsMetrics
     * @return list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>
     */
    private function broadPasses(array $records, MaxBidParameters $defaults, array $defaultsMetrics, int $minimumProfitable): array
    {
        $firstPass = [];

        foreach (self::GRID_DECAYS as $decayBreak) {
            foreach (self::GRID_DECAYS as $decayMatchweek) {
                foreach (self::GRID_SPORT_DAILY_RATES as $sportDailyRate) {
                    foreach (self::GRID_PROXIMITY_HALF_LIVES as $halfLife) {
                        $parameters = new MaxBidParameters(
                            incrementDecayBreak: $decayBreak,
                            incrementDecayMatchweek: $decayMatchweek,
                            sportDailyRate: $sportDailyRate,
                            proximityHalfLifeDays: $halfLife,
                        );
                        $firstPass[] = ['parameters' => $parameters, 'metrics' => $this->metrics($this->replay($records, $parameters, false)['Total'])];
                    }
                }
            }
        }

        $firstRanking = $this->rank($firstPass, $minimumProfitable);
        $this->printPass('Pasada 1: decay parón × decay jornada × ritmo deportivo × vida media (reglas por defecto)', $firstPass, $firstRanking, $defaults, $defaultsMetrics, $minimumProfitable);
        $best = $firstRanking[0]['parameters'] ?? $defaults;

        $secondPass = [];

        foreach (self::GRID_BENCHES_BEFORE_UNPROFITABLE as $benches) {
            foreach (self::GRID_BENCH_INCREMENT_FACTORS as $benchFactor) {
                foreach (BadScoreRule::cases() as $badScoreRule) {
                    $parameters = new MaxBidParameters(
                        incrementDecayBreak: $best->incrementDecayBreak,
                        incrementDecayMatchweek: $best->incrementDecayMatchweek,
                        sportDailyRate: $best->sportDailyRate,
                        proximityHalfLifeDays: $best->proximityHalfLifeDays,
                        benchIncrementFactor: $benchFactor,
                        benchesBeforeUnprofitable: $benches,
                        badScoreRule: $badScoreRule,
                    );
                    $secondPass[] = ['parameters' => $parameters, 'metrics' => $this->metrics($this->replay($records, $parameters, false)['Total'])];
                }
            }
        }

        $secondRanking = $this->rank($secondPass, $minimumProfitable);
        $this->printPass('Pasada 2: con los valores continuos de la mejor de la pasada 1, banquillos antes de no rentable × factor banquillo × mala nota', $secondPass, $secondRanking, $defaults, $defaultsMetrics, $minimumProfitable);

        return $secondRanking;
    }

    /**
     * A qualitative check, never used for ranking: how the control player's
     * days fare under the top three combinations and the defaults.
     *
     * @param  list<array{0: MaxBidInputs, 1: list<int>, 2: MarketTrend|null}>  $records
     * @param  list<int>  $controlRecords
     * @param  array{player: Player, from: string, to: string}  $control
     * @param  list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>  $ranking
     */
    private function printControl(array $records, array $controlRecords, array $control, array $ranking, MaxBidParameters $defaults): void
    {
        $candidates = [];

        foreach (array_slice($ranking, 0, 3) as $index => $combination) {
            $candidates['#'.($index + 1)] = $combination['parameters'];
        }

        $candidates['defaults'] = $defaults;
        $rows = [];

        foreach ($candidates as $label => $parameters) {
            $profitableDays = 0;
            $probabilitySum = 0.0;

            foreach ($controlRecords as $index) {
                [$inputs, $actual] = $records[$index];
                $estimate = MaxBidCalculator::estimateFromInputs($inputs, $parameters);

                if ($estimate->status === MaxBidStatus::Profitable) {
                    $profitableDays++;
                    $probabilitySum += $this->outcome($estimate, $actual)['probability'] ?? 0.0;
                }
            }

            $rows[] = [
                $label,
                $this->streakLabel($parameters),
                (string) count($controlRecords),
                (string) $profitableDays,
                $this->percent($profitableDays === 0 ? null : $probabilitySum / $profitableDays),
            ];
        }

        $this->newLine();
        $this->info("Control (cualitativo, no puntúa): {$control['player']->nickname}, {$control['from']} a {$control['to']}");
        $this->table(['#', 'Racha', 'Días', 'Días rentables', 'Prob. real media'], $rows);
    }

    private function streakLabel(MaxBidParameters $parameters): string
    {
        return $parameters->streakExceptionPace === null
            ? 'no'
            : sprintf('≥ %s %%/d, ≥ %d pts', number_format($parameters->streakExceptionPace * 100, 0, ',', '.'), $parameters->streakExceptionTeamPoints);
    }

    /**
     * Scores the formula with `$parameters` over every gathered record.
     *
     * @param  list<array{0: MaxBidInputs, 1: list<int>, 2: MarketTrend|null}>  $records
     * @return array<string, array{count: int, profitableCount: int, profitableProbabilitySum: float, unprofitableCount: int, unprofitableRoseCount: int, errors: list<float>}>
     */
    private function replay(array $records, MaxBidParameters $parameters, bool $breakdown): array
    {
        $groups = [];

        foreach ($records as [$inputs, $actual, $trend]) {
            $estimate = MaxBidCalculator::estimateFromInputs($inputs, $parameters);
            ['error' => $error, 'probability' => $probability, 'rose' => $rose] = $this->outcome($estimate, $actual);
            $groupNames = $breakdown
                ? ['Total', 'tendencia: '.($trend === null ? 'sin tendencia' : $trend->value), 'fase: '.($inputs->isBreak() ? 'parón' : 'semana de partido')]
                : ['Total'];

            foreach ($groupNames as $group) {
                $this->addToGroup($groups, $group, $estimate->status, $error, $probability, $rose);
            }
        }

        return $groups;
    }

    /**
     * @param  array{count: int, profitableCount: int, profitableProbabilitySum: float, unprofitableCount: int, unprofitableRoseCount: int, errors: list<float>}  $group
     * @return array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}
     */
    private function metrics(array $group): array
    {
        return [
            'profitableCount' => $group['profitableCount'],
            'probability' => $group['profitableCount'] === 0 ? null : $group['profitableProbabilitySum'] / $group['profitableCount'],
            'unprofitableCount' => $group['unprofitableCount'],
            'falseNegativeRate' => $group['unprofitableCount'] === 0 ? null : $group['unprofitableRoseCount'] / $group['unprofitableCount'],
            'medianError' => $this->median($group['errors']),
        ];
    }

    /**
     * The combinations calling at least `$minimumProfitable` estimates
     * profitable, best first: realised probability closest to the target,
     * then fewer false negatives, then a lower median projection error.
     *
     * @param  list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>  $combinations
     * @return list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>
     */
    private function rank(array $combinations, int $minimumProfitable): array
    {
        $eligible = array_values(array_filter(
            $combinations,
            fn (array $combination): bool => $combination['metrics']['profitableCount'] >= $minimumProfitable,
        ));

        usort($eligible, fn (array $a, array $b): int => $this->rankingKey($a['metrics']) <=> $this->rankingKey($b['metrics']));

        return $eligible;
    }

    /**
     * @param  array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}  $metrics
     * @return array{0: float, 1: float, 2: float}
     */
    private function rankingKey(array $metrics): array
    {
        return [
            $metrics['probability'] === null ? INF : abs($metrics['probability'] - MaxBidCalculator::CONFIDENCE),
            $metrics['falseNegativeRate'] ?? INF,
            $metrics['medianError'] ?? INF,
        ];
    }

    /**
     * @param  list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>  $combinations
     * @param  list<array{parameters: MaxBidParameters, metrics: array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}}>  $ranking
     * @param  array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}  $defaultsMetrics
     */
    private function printPass(string $title, array $combinations, array $ranking, MaxBidParameters $defaults, array $defaultsMetrics, int $minimumProfitable): void
    {
        $this->newLine();
        $this->info($title);
        $this->line(sprintf(
            '%d combinaciones; %d descartadas por dar menos de %d rentables (%d %% de las de los valores por defecto).',
            count($combinations),
            count($combinations) - count($ranking),
            $minimumProfitable,
            self::MINIMUM_PROFITABLE_SHARE * 100,
        ));

        $rows = [];

        foreach (array_slice($ranking, 0, self::GRID_TOP) as $index => $combination) {
            $rows[] = $this->gridRow('#'.($index + 1), $combination['parameters'], $combination['metrics']);
        }

        $rows[] = $this->gridRow('defaults', $defaults, $defaultsMetrics);

        $this->table(
            ['#', 'Decay parón', 'Decay jornada', 'Ritmo dep.', 'Vida media', 'Factor banq.', 'Banq. para no rent.', 'Mala nota', 'Racha', 'Rentables', 'Prob. real media', '|Δ 75 %|', 'Sin rentab.', 'Falsos neg.', 'Error mediana'],
            $rows,
        );
    }

    /**
     * @param  array{profitableCount: int, probability: float|null, unprofitableCount: int, falseNegativeRate: float|null, medianError: float|null}  $metrics
     * @return list<string>
     */
    private function gridRow(string $label, MaxBidParameters $parameters, array $metrics): array
    {
        $number = fn (float $value, int $decimals): string => number_format($value, $decimals, ',', '.');

        return [
            $label,
            $number($parameters->incrementDecayBreak, 2),
            $number($parameters->incrementDecayMatchweek, 2),
            $number($parameters->sportDailyRate, 3),
            $number($parameters->proximityHalfLifeDays, 0).' d',
            $number($parameters->benchIncrementFactor, 2),
            $parameters->benchesBeforeUnprofitable === 0 ? 'nunca' : (string) $parameters->benchesBeforeUnprofitable,
            $parameters->badScoreRule->label(),
            $this->streakLabel($parameters),
            (string) $metrics['profitableCount'],
            $this->percent($metrics['probability']),
            $this->percent($metrics['probability'] === null ? null : abs($metrics['probability'] - MaxBidCalculator::CONFIDENCE)),
            (string) $metrics['unprofitableCount'],
            $this->percent($metrics['falseNegativeRate']),
            $this->percent($metrics['medianError']),
        ];
    }

    /**
     * The real values from `$day` to the end of the lock (day 0…14), or null
     * when any of those days is missing.
     *
     * @param  array<string, int>  $values  date → value
     * @return list<int>|null
     */
    private function actualValues(array $values, CarbonImmutable $day): ?array
    {
        $actual = [];

        for ($offset = 0; $offset <= MaxBidCalculator::LOCK_DAYS; $offset++) {
            $value = $values[$day->addDays($offset)->toDateString()] ?? null;

            if ($value === null) {
                return null;
            }

            $actual[] = $value;
        }

        return $actual;
    }

    /**
     * @param  array<string, int>  $values  date → value, oldest first
     */
    private function trend(array $values, CarbonImmutable $day): ?MarketTrend
    {
        return MarketTrend::fromDailyValues(array_values(array_filter(
            $values,
            fn (string $date): bool => $date <= $day->toDateString(),
            ARRAY_FILTER_USE_KEY,
        )));
    }

    /**
     * How an estimate did against the real values: its day-14 projection
     * error relative to the value, the real probability that the best offer
     * of the lock beat its bid (profitable only), and whether the value rose.
     *
     * @param  list<int>  $actual  day 0…14
     * @return array{error: float, probability: float|null, rose: bool}
     */
    private function outcome(MaxBidEstimate $estimate, array $actual): array
    {
        return [
            'error' => abs($actual[MaxBidCalculator::LOCK_DAYS] - ($estimate->projection[MaxBidCalculator::LOCK_DAYS] ?? 0)) / max($estimate->value, 1),
            'probability' => $estimate->bid !== null
                ? 1 - MaxBidCalculator::bestOfferProbabilityAtMost($estimate->bid, $actual)
                : null,
            'rose' => $actual[MaxBidCalculator::LOCK_DAYS] > $actual[0],
        ];
    }

    /**
     * Adds one replay record to a report group's running totals, without keeping the record itself
     * (only its error contributes a float to the list kept for the group's median).
     *
     * @param  array<string, array{count: int, profitableCount: int, profitableProbabilitySum: float, unprofitableCount: int, unprofitableRoseCount: int, errors: list<float>}>  $groups
     */
    private function addToGroup(array &$groups, string $group, MaxBidStatus $status, float $error, ?float $probability, bool $rose): void
    {
        $groups[$group] ??= [
            'count' => 0,
            'profitableCount' => 0,
            'profitableProbabilitySum' => 0.0,
            'unprofitableCount' => 0,
            'unprofitableRoseCount' => 0,
            'errors' => [],
        ];

        $groups[$group]['count']++;
        $groups[$group]['errors'][] = $error;

        if ($status === MaxBidStatus::Profitable) {
            $groups[$group]['profitableCount']++;
            $groups[$group]['profitableProbabilitySum'] += $probability ?? 0.0;
        } else {
            $groups[$group]['unprofitableCount']++;

            if ($rose) {
                $groups[$group]['unprofitableRoseCount']++;
            }
        }
    }

    /**
     * @param  array<string, array{count: int, profitableCount: int, profitableProbabilitySum: float, unprofitableCount: int, unprofitableRoseCount: int, errors: list<float>}>  $groups
     * @return list<list<string>>
     */
    private function rows(array $groups): array
    {
        $rows = [];

        foreach ($groups as $group => $data) {
            $errors = $data['errors'];
            $errorCount = count($errors);

            $rows[] = [
                $group,
                (string) $data['count'],
                (string) $data['profitableCount'],
                $this->percent($data['profitableCount'] === 0 ? null : $data['profitableProbabilitySum'] / $data['profitableCount']),
                (string) $data['unprofitableCount'],
                $this->percent($data['unprofitableCount'] === 0 ? null : $data['unprofitableRoseCount'] / $data['unprofitableCount']),
                $this->percent($errorCount === 0 ? null : array_sum($errors) / $errorCount),
                $this->percent($this->median($errors)),
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($a[0] === 'Total' ? '' : $a[0]) <=> ($b[0] === 'Total' ? '' : $b[0]));

        return $rows;
    }

    /**
     * The upper median (the middle of the sorted list), null for none.
     *
     * @param  list<float>  $values
     */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }

        sort($values);

        return $values[intdiv(count($values), 2)];
    }

    private function percent(?float $value): string
    {
        return $value === null ? '—' : number_format($value * 100, 1, ',', '.').' %';
    }

    /**
     * @param  list<array{date: string, value: int, status: MaxBidStatus, bid: int|null, projectedDay14: int, actualDay14: int, probability: float|null, rose: bool}>  $records
     * @return list<list<string>>
     */
    private function playerRows(array $records): array
    {
        return array_map(fn (array $record): array => [
            $record['date'],
            (string) $record['value'],
            $record['status']->value,
            $record['bid'] !== null ? (string) $record['bid'] : '—',
            (string) $record['projectedDay14'],
            (string) $record['actualDay14'],
            $record['status'] === MaxBidStatus::Profitable
                ? $this->percent($record['probability'])
                : ($record['rose'] ? 'subió' : 'no subió'),
        ], $records);
    }
}
