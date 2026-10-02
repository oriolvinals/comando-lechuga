<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClauseSnapshotSource;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\PlayerMarket;
use App\Models\SeasonManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The raise a sync clause row shows in its `raise_amount`, by the same rules
 * as {@see ClauseRaiseDetector}: over the previous sync row of the holding,
 * the clause minus max(previous clause, the value in that same response);
 * on the holding's first row, the clause minus max(price paid — or 5/3 of the
 * joining value for an initial-squad player — 1 M, highest value since).
 * For display only: the balances keep reading the detector, so nothing counts
 * twice. PRIVATE: never used by /api.
 */
final class ClauseSnapshotRaise
{
    public function __construct(private readonly ManualClauseRaise $manualClauseRaise) {}

    /**
     * @param  int|null  $rowId  the row itself when backfilling a stored one: only rows stored before it count
     */
    public function forSync(int $managerId, int $playerId, int $clause, int $marketValue, CarbonImmutable $at, ?int $rowId = null): int
    {
        $acquisition = Activity::query()
            ->where('source_season_manager_id', $managerId)
            ->where('player_id', $playerId)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->where('occurred_at', '<=', $at)
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        $previous = ManagerPlayerClauseSnapshot::query()
            ->where('season_manager_id', $managerId)
            ->where('player_id', $playerId)
            ->where('source', ClauseSnapshotSource::Sync)
            ->where('captured_at', '<=', $at)
            ->when($acquisition !== null, fn (Builder $query): Builder => $query->where('captured_at', '>=', $acquisition->occurred_at))
            ->when($rowId !== null, fn (Builder $query): Builder => $query->where('id', '<', $rowId))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->value('buyout_clause');

        $reference = $previous !== null
            ? max((int) $previous, $marketValue)
            : $this->firstRowReference($managerId, $playerId, $acquisition, $at);

        if ($reference === null) {
            return 0;
        }

        $raise = $clause - $reference;

        return $raise > $clause * ClauseRaiseDetector::NOISE_RATIO ? $raise : 0;
    }

    /** Null for an initial-squad holding without a value on the joining day: any clause would read as a phantom raise. */
    private function firstRowReference(int $managerId, int $playerId, ?Activity $acquisition, CarbonImmutable $at): ?int
    {
        $since = $acquisition !== null
            ? $acquisition->occurred_at
            : $this->manualClauseRaise->joinedAt(SeasonManager::query()->findOrFail($managerId));
        $base = (int) $acquisition?->amount;

        if ($acquisition === null) {
            $joiningValue = PlayerMarket::query()->where('player_id', $playerId)->whereDate('date', '<=', $since)->orderByDesc('date')->value('value');

            if ($joiningValue === null) {
                return null;
            }

            $base = intdiv((int) $joiningValue * ClauseRaiseDetector::INITIAL_CLAUSE_NUMERATOR, ClauseRaiseDetector::INITIAL_CLAUSE_DENOMINATOR);
        }

        $maxValue = (int) PlayerMarket::query()
            ->where('player_id', $playerId)
            ->whereDate('date', '>=', $since)
            ->whereDate('date', '<=', $at)
            ->max('value');

        return max(ClauseRaiseDetector::MIN_CLAUSE, $base, $maxValue);
    }
}
