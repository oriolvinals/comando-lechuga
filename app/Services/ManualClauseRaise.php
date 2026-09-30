<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClauseSnapshotSource;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\PlayerMarket;
use App\Models\SeasonManager;
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

    private const array MOVES = [SeasonActivityType::Signing, SeasonActivityType::Buyout, SeasonActivityType::Sale];

    /**
     * The previous clause is the automatic clause just before the moment:
     * the latest history row of the current holding (since the manager's last
     * signing or buyout of the player, other than the row being edited, and
     * skipping sync rows within 24 h that may already include this raise),
     * never below max(1 M, price paid, highest value since the purchase). An
     * initial-squad holding starts at 5/3 of the value on the joining day.
     * (The detector's feed-gap holdings, based on the value alone, never get
     * here: ownedAt() rejects a moment after someone else moved the player.)
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

        $since = $acquiredAt ?? $this->joinedAt(SeasonManager::query()->findOrFail($managerId));
        $maxValue = (int) PlayerMarket::query()
            ->where('player_id', $playerId)
            ->whereDate('date', '>=', $since)
            ->whereDate('date', '<=', $at)
            ->max('value');
        $initialClause = 0;

        if ($acquisition === null) {
            $joiningValue = (int) PlayerMarket::query()->where('player_id', $playerId)->whereDate('date', '<=', $since)->orderByDesc('date')->value('value');
            $initialClause = intdiv($joiningValue * ClauseRaiseDetector::INITIAL_CLAUSE_NUMERATOR, ClauseRaiseDetector::INITIAL_CLAUSE_DENOMINATOR);
        }

        $previous = max(ClauseRaiseDetector::MIN_CLAUSE, (int) $latestRow, (int) $acquisition?->amount, $maxValue, $initialClause);

        $raise = $newClause !== null ? $newClause - $previous : (int) $paid * 2;

        return ['previous' => $previous, 'clause' => $previous + $raise, 'raise' => $raise];
    }

    /**
     * Whether the manager owned the player at that moment: after his last
     * signing or buyout of him and before the player moved again, or, with
     * no move before, from the joining (initial squad) until the player
     * first leaves him. A raise outside every holding would count on top of
     * the inference of a holding it doesn't belong to.
     */
    public function ownedAt(int $managerId, int $playerId, CarbonImmutable $at): bool
    {
        $manager = SeasonManager::query()->findOrFail($managerId);
        $moves = Activity::query()
            ->where('season_id', $manager->season_id)
            ->where('player_id', $playerId)
            ->whereIn('type', self::MOVES)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();
        $previous = $moves->last(fn (Activity $move): bool => $move->occurred_at->lessThanOrEqualTo($at));

        if ($previous instanceof Activity) {
            return $previous->type !== SeasonActivityType::Sale && $previous->source_season_manager_id === $managerId;
        }

        if ($at->lessThan($this->joinedAt($manager))) {
            return false;
        }

        $next = $moves->first(fn (Activity $move): bool => $move->occurred_at->greaterThan($at));

        if ($next instanceof Activity) {
            return $next->type === SeasonActivityType::Sale
                ? $next->source_season_manager_id === $managerId
                : $next->target_season_manager_id === $managerId;
        }

        return ManagerPlayer::query()->where('season_manager_id', $managerId)->where('player_id', $playerId)->exists();
    }

    /** The manager's joined_league, or the season start when the feed doesn't have it. */
    private function joinedAt(SeasonManager $manager): CarbonImmutable
    {
        return Activity::query()
            ->where('season_id', $manager->season_id)
            ->where('source_season_manager_id', $manager->id)
            ->where('type', SeasonActivityType::JoinedLeague)
            ->orderBy('occurred_at')
            ->value('occurred_at') ?? $manager->season->start_date;
    }
}
