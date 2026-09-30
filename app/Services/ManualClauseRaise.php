<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\PlayerMarket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Turns a user-entered clause raise into (previous clause, new clause, raise).
 * Raising a clause by X costs X/2, so "paid" doubles into the raise.
 * PRIVATE: never used by /api.
 */
final class ManualClauseRaise
{
    /**
     * The previous clause is the latest history row of the holding before the
     * moment (other than the row being edited); without one, max(1 M, market
     * value that day).
     *
     * @return array{previous: int, clause: int, raise: int}
     */
    public function derive(int $managerId, int $playerId, CarbonImmutable $at, ?int $newClause, ?int $paid, ?int $ignoreId = null): array
    {
        $previous = ManagerPlayerClauseSnapshot::query()
            ->where('season_manager_id', $managerId)
            ->where('player_id', $playerId)
            ->where('captured_at', '<', $at)
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->value('buyout_clause');
        $previous ??= max(
            ClauseRaiseDetector::MIN_CLAUSE,
            (int) PlayerMarket::query()->where('player_id', $playerId)->whereDate('date', '<=', $at)->orderByDesc('date')->value('value'),
        );
        $previous = (int) $previous;

        $raise = $newClause !== null ? $newClause - $previous : (int) $paid * 2;

        return ['previous' => $previous, 'clause' => $previous + $raise, 'raise' => $raise];
    }
}
