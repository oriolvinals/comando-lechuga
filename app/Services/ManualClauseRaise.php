<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClauseSnapshotSource;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
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
    /** Sync rows this close before the entry may already carry the raise itself (the user's time is approximate). */
    public const int SYNC_WINDOW_HOURS = 24;

    /**
     * The previous clause is the automatic clause just before the moment:
     * the latest history row of the current holding (since the manager's last
     * signing or buyout of the player, other than the row being edited, and
     * skipping sync rows within 24 h that may already include this raise),
     * never below max(1 M, price paid, market value that day).
     *
     * @return array{previous: int, clause: int, raise: int}
     */
    public function derive(int $managerId, int $playerId, CarbonImmutable $at, ?int $newClause, ?int $paid, ?int $ignoreId = null): array
    {
        $acquisition = Activity::query()
            ->where('source_season_manager_id', $managerId)
            ->where('player_id', $playerId)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->where('occurred_at', '<=', $at)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();
        $acquiredAt = $acquisition?->occurred_at;
        $syncWindowStart = $at->subHours(self::SYNC_WINDOW_HOURS);

        $latestRow = ManagerPlayerClauseSnapshot::query()
            ->where('season_manager_id', $managerId)
            ->where('player_id', $playerId)
            ->where('captured_at', '<', $at)
            ->when($acquiredAt !== null, fn (Builder $query): Builder => $query->where('captured_at', '>=', $acquiredAt))
            ->when($ignoreId !== null, fn (Builder $query): Builder => $query->whereKeyNot($ignoreId))
            ->where(fn (Builder $query): Builder => $query
                ->where('source', ClauseSnapshotSource::Manual)
                ->orWhere('captured_at', '<', $syncWindowStart))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->value('buyout_clause');
        $marketValue = (int) PlayerMarket::query()->where('player_id', $playerId)->whereDate('date', '<=', $at)->orderByDesc('date')->value('value');
        $previous = max(ClauseRaiseDetector::MIN_CLAUSE, (int) $latestRow, (int) $acquisition?->amount, $marketValue);

        $raise = $newClause !== null ? $newClause - $previous : (int) $paid * 2;

        return ['previous' => $previous, 'clause' => $previous + $raise, 'raise' => $raise];
    }
}
