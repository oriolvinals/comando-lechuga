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
            $players = Player::query()->where('nickname', $nickname)->get();

            if ($players->isEmpty()) {
                $this->error("No existe ningún jugador con el apodo «{$nickname}».");

                return self::FAILURE;
            }
        } else {
            $players = Player::query()
                ->whereIn('team_id', $season->teams()->select('teams.id'))
                ->whereIn('status', [PlayerStatus::Ok, PlayerStatus::Doubtful])
                ->get();
        }

        /** @var array<int, array<string, int>> $valuesByPlayer player id → date → value */
        $valuesByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $players->pluck('id'))
            ->get(['player_id', 'date', 'value'])
            ->groupBy('player_id')
            ->map(fn ($markets) => $markets->mapWithKeys(fn (PlayerMarket $market): array => [
                $market->date->toDateString() => $market->value,
            ])->all())
            ->all();

        /** @var list<array{groups: list<string>, status: MaxBidStatus, error: float, probability: float|null, rose: bool, date: string, value: int, bid: int|null, projectedDay14: int, actualDay14: int}> $records */
        $records = [];

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

                $records[] = [
                    'groups' => ['Total', "tendencia: {$trend}", "fase: {$phase}"],
                    'status' => $estimate->status,
                    'error' => abs($actual[MaxBidCalculator::LOCK_DAYS] - $estimate->projection[MaxBidCalculator::LOCK_DAYS]) / max($estimate->value, 1),
                    'probability' => $estimate->bid !== null
                        ? 1 - MaxBidCalculator::bestOfferProbabilityAtMost($estimate->bid, $actual)
                        : null,
                    'rose' => $actual[MaxBidCalculator::LOCK_DAYS] > $actual[0],
                    'date' => $day->toDateString(),
                    'value' => $estimate->value,
                    'bid' => $estimate->bid,
                    'projectedDay14' => $estimate->projection[MaxBidCalculator::LOCK_DAYS],
                    'actualDay14' => $actual[MaxBidCalculator::LOCK_DAYS],
                ];
            }
        }

        $this->table(
            ['Grupo', 'Estimaciones', 'Rentables', 'Prob. real media (obj. 75 %)', 'Sin rentab.', 'Falsos negativos', 'Error proy. medio', 'Error proy. mediana'],
            $this->rows($records),
        );

        if ($nickname !== null) {
            $this->table(
                ['Fecha', 'Valor', 'Estado', 'Puja', 'Proy. día 14', 'Real día 14', 'Prob. real / ¿subió?'],
                $this->playerRows($records),
            );
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array{groups: list<string>, status: MaxBidStatus, error: float, probability: float|null, rose: bool, date: string, value: int, bid: int|null, projectedDay14: int, actualDay14: int}>  $records
     * @return list<list<string>>
     */
    private function rows(array $records): array
    {
        $percent = fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.').' %';

        $rows = collect($records)
            ->flatMap(fn (array $record): array => array_map(fn (string $group): array => ['group' => $group, 'record' => $record], $record['groups']))
            ->groupBy('group')
            ->map(function ($entries, string $group) use ($percent): array {
                $records = collect($entries)->pluck('record');
                $profitable = $records->where('status', MaxBidStatus::Profitable);
                $unprofitable = $records->where('status', MaxBidStatus::Unprofitable);
                $errors = $records->pluck('error')->sort()->values();

                return [
                    $group,
                    (string) $records->count(),
                    (string) $profitable->count(),
                    $percent($profitable->isEmpty() ? null : $profitable->avg('probability')),
                    (string) $unprofitable->count(),
                    $percent($unprofitable->isEmpty() ? null : $unprofitable->where('rose', true)->count() / $unprofitable->count()),
                    $percent($errors->avg()),
                    $percent($errors->isEmpty() ? null : $errors[intdiv($errors->count(), 2)]),
                ];
            })
            ->sortBy(fn (array $row): string => $row[0] === 'Total' ? '' : $row[0])
            ->values()
            ->all();

        return array_values($rows);
    }

    /**
     * @param  list<array{groups: list<string>, status: MaxBidStatus, error: float, probability: float|null, rose: bool, date: string, value: int, bid: int|null, projectedDay14: int, actualDay14: int}>  $records
     * @return list<list<string>>
     */
    private function playerRows(array $records): array
    {
        $percent = fn (?float $value): string => $value === null ? '—' : number_format($value * 100, 1, ',', '.').' %';

        $rows = collect($records)
            ->sortBy('date')
            ->map(fn (array $record): array => [
                $record['date'],
                (string) $record['value'],
                $record['status']->value,
                $record['bid'] !== null ? (string) $record['bid'] : '—',
                (string) $record['projectedDay14'],
                (string) $record['actualDay14'],
                $record['status'] === MaxBidStatus::Profitable
                    ? $percent($record['probability'])
                    : ($record['rose'] ? 'subió' : 'no subió'),
            ])
            ->values()
            ->all();

        return array_values($rows);
    }
}
