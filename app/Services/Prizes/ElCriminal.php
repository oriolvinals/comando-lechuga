<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;

/**
 * What each manager paid above market value: purchases (`signing`, won
 * bids included) and clauses (`buyout`) only. The value is the player's
 * market value that day, or the last one before; an operation with no
 * earlier value does not count.
 */
final class ElCriminal implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function rows(Season $season): array
    {
        $operations = Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->whereNotNull('player_id')
            ->whereNotNull('amount')
            ->orderBy('occurred_at')
            ->get(['source_season_manager_id', 'player_id', 'amount', 'occurred_at']);

        $valuesByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $operations->pluck('player_id')->unique())
            ->orderBy('date')
            ->get(['player_id', 'date', 'value'])
            ->groupBy('player_id');

        /** @var array<int, array{total: int, worst: array{player_id: int, overpaid: int}|null}> $totals */
        $totals = [];

        foreach ($operations as $operation) {
            $day = $operation->occurred_at->toDateString();
            $value = $valuesByPlayer->get($operation->player_id)
                ?->filter(fn (PlayerMarket $market): bool => $market->date->toDateString() <= $day)
                ->last()?->value;

            if ($value === null) {
                continue;
            }

            $overpaid = max(0, (int) $operation->amount - (int) $value);
            $managerId = $operation->source_season_manager_id;
            $current = $totals[$managerId] ?? ['total' => 0, 'worst' => null];
            $current['total'] += $overpaid;

            if ($overpaid > 0 && ($current['worst'] === null || $overpaid > $current['worst']['overpaid'])) {
                $current['worst'] = ['player_id' => (int) $operation->player_id, 'overpaid' => $overpaid];
            }

            $totals[$managerId] = $current;
        }

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, $totals[$id]['total'] ?? 0, ['worst' => $totals[$id]['worst'] ?? null]), $this->managerIds($season));
    }
}
