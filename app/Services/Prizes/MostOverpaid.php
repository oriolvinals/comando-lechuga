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
 * earlier value does not count. Operations and values are both walked in
 * date order, so each player's value history is read once.
 */
final class MostOverpaid implements PrizeCalculator
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

        /** @var array<int, list<array{day: string, value: int}>> $historyByPlayer */
        $historyByPlayer = [];

        PlayerMarket::query()
            ->toBase()
            ->whereIn('player_id', $operations->pluck('player_id')->unique())
            ->orderBy('player_id')
            ->orderBy('date')
            ->get(['player_id', 'date', 'value'])
            ->each(function (object $market) use (&$historyByPlayer): void {
                $historyByPlayer[(int) $market->player_id][] = ['day' => substr((string) $market->date, 0, 10), 'value' => (int) $market->value];
            });

        /** @var array<int, array{next: int, value: int|null}> $cursors per player: his next unread value and the last one read */
        $cursors = [];

        /** @var array<int, array{total: int, worst: array{player_id: int, overpaid: int}|null}> $totals */
        $totals = [];

        foreach ($operations as $operation) {
            $playerId = (int) $operation->player_id;
            $day = $operation->occurred_at->toDateString();
            $history = $historyByPlayer[$playerId] ?? [];
            $cursor = $cursors[$playerId] ?? ['next' => 0, 'value' => null];

            while (isset($history[$cursor['next']]) && $history[$cursor['next']]['day'] <= $day) {
                $cursor['value'] = $history[$cursor['next']]['value'];
                $cursor['next']++;
            }

            $cursors[$playerId] = $cursor;

            if ($cursor['value'] === null) {
                continue;
            }

            $overpaid = max(0, (int) $operation->amount - $cursor['value']);
            $managerId = $operation->source_season_manager_id;
            $current = $totals[$managerId] ?? ['total' => 0, 'worst' => null];
            $current['total'] += $overpaid;

            if ($overpaid > 0 && ($current['worst'] === null || $overpaid > $current['worst']['overpaid'])) {
                $current['worst'] = ['player_id' => $playerId, 'overpaid' => $overpaid];
            }

            $totals[$managerId] = $current;
        }

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, $totals[$id]['total'] ?? 0, ['worst' => $totals[$id]['worst'] ?? null]), $this->managerIds($season));
    }
}
