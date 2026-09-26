<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\MarketTrend;
use App\Enums\MaxBidStatus;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\MaxBidCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:backtest-max-bid {--from= : First reference date (Y-m-d)} {--to= : Last reference date (Y-m-d)} {--player= : Nickname of one player to replay day by day}')]
#[Description('Replay the max bid model over the market history and report how it would have done')]
class BacktestMaxBid extends Command
{
    public function handle(MaxBidCalculator $calculator): int
    {
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

        /** @var array<string, array{count: int, profitableCount: int, profitableProbabilitySum: float, unprofitableCount: int, unprofitableRoseCount: int, errors: list<float>}> $groups */
        $groups = [];

        /** @var list<array{date: string, value: int, status: MaxBidStatus, bid: int|null, projectedDay14: int, actualDay14: int, probability: float|null, rose: bool}> $playerRows */
        $playerRows = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            foreach ($players as $player) {
                $values = $valuesByPlayer[$player->id] ?? [];
                $actual = [];

                for ($offset = 0; $offset <= MaxBidCalculator::LOCK_DAYS; $offset++) {
                    $actual[] = $values[$day->addDays($offset)->toDateString()] ?? null;
                }

                if (in_array(null, $actual, true)) {
                    continue;
                }

                $estimate = $calculator->estimate($player, $season, $day);

                if (!in_array($estimate->status, [MaxBidStatus::Profitable, MaxBidStatus::Unprofitable], true)) {
                    continue;
                }

                $history = array_values(array_filter(
                    $values,
                    fn (string $date): bool => $date <= $day->toDateString(),
                    ARRAY_FILTER_USE_KEY,
                ));
                $trendCase = MarketTrend::fromDailyValues($history);
                $trend = $trendCase === null ? 'sin tendencia' : $trendCase->value;
                $nextMatchDays = $estimate->upcomingRivals[0]['days_until'] ?? null;
                $phase = $nextMatchDays === null || $nextMatchDays > 7 ? 'parón' : 'semana de partido';

                $error = abs($actual[MaxBidCalculator::LOCK_DAYS] - $estimate->projection[MaxBidCalculator::LOCK_DAYS]) / max($estimate->value, 1);
                $probability = $estimate->bid !== null
                    ? 1 - MaxBidCalculator::bestOfferProbabilityAtMost($estimate->bid, $actual)
                    : null;
                $rose = $actual[MaxBidCalculator::LOCK_DAYS] > $actual[0];

                foreach (['Total', "tendencia: {$trend}", "fase: {$phase}"] as $group) {
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
        $percent = fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.').' %';

        $rows = [];

        foreach ($groups as $group => $data) {
            $errors = $data['errors'];
            sort($errors);
            $errorCount = count($errors);

            $rows[] = [
                $group,
                (string) $data['count'],
                (string) $data['profitableCount'],
                $percent($data['profitableCount'] === 0 ? null : $data['profitableProbabilitySum'] / $data['profitableCount']),
                (string) $data['unprofitableCount'],
                $percent($data['unprofitableCount'] === 0 ? null : $data['unprofitableRoseCount'] / $data['unprofitableCount']),
                $percent($errorCount === 0 ? null : array_sum($errors) / $errorCount),
                $percent($errorCount === 0 ? null : $errors[intdiv($errorCount, 2)]),
            ];
        }

        usort($rows, fn (array $a, array $b): int => ($a[0] === 'Total' ? '' : $a[0]) <=> ($b[0] === 'Total' ? '' : $b[0]));

        return $rows;
    }

    /**
     * @param  list<array{date: string, value: int, status: MaxBidStatus, bid: int|null, projectedDay14: int, actualDay14: int, probability: float|null, rose: bool}>  $records
     * @return list<list<string>>
     */
    private function playerRows(array $records): array
    {
        $percent = fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.').' %';

        return array_map(fn (array $record): array => [
            $record['date'],
            (string) $record['value'],
            $record['status']->value,
            $record['bid'] !== null ? (string) $record['bid'] : '—',
            (string) $record['projectedDay14'],
            (string) $record['actualDay14'],
            $record['status'] === MaxBidStatus::Profitable
                ? $percent($record['probability'])
                : ($record['rose'] ? 'subió' : 'no subió'),
        ], $records);
    }
}
