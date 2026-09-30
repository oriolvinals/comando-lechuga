<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use Carbon\CarbonImmutable;

/**
 * Every manager's cash range, squad value and total for the god-mode radar.
 * Real cash is the connected account's latest snapshot plus what happened
 * after it (moves, and the certain raises it paid for). PRIVATE: never used by /api.
 */
final class ManagerBalances
{
    public const int STARTING_CASH = 100_000_000;

    /** Share of the days on which managers claim the daily bonus (the user's estimate). */
    public const float DAILY_BONUS_CLAIM_RATE = 0.67;

    public function __construct(
        private readonly DailyBonusCalendar $dailyBonus,
        private readonly ClauseRaiseDetector $raiseDetector,
    ) {}

    /**
     * @return array<int, ManagerBalance> season manager id => balance, in ranking order
     */
    public function forSeason(Season $season, CarbonImmutable $now): array
    {
        $timezone = (string) config('app.timezone');
        $now = $now->setTimezone($timezone);
        $managers = SeasonManager::query()->where('season_id', $season->id)->orderBy('position')->orderBy('id')->get();
        $activity = $this->activitySums($season);
        $raises = $this->raiseDetector->forSeason($season, $now);
        $squad = $this->squadValues($season);
        $connectedId = $this->connectedManagerId($season);
        $connectedSnapshot = $connectedId === null ? null : ManagerBalanceSnapshot::query()
            ->where('season_manager_id', $connectedId)
            ->orderByDesc('captured_at')
            ->first();
        $joinedAt = Activity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::JoinedLeague)
            ->orderBy('occurred_at')
            ->get()
            ->unique('source_season_manager_id')
            ->mapWithKeys(fn (Activity $joined): array => [$joined->source_season_manager_id => $joined->occurred_at]);

        $balances = [];

        foreach ($managers as $manager) {
            $since = ($joinedAt->get($manager->id) ?? $season->start_date)->setTimezone($timezone);
            $activityCash = self::STARTING_CASH + ($activity[$manager->id] ?? 0);
            $dailyBonus = (int) round(self::DAILY_BONUS_CLAIM_RATE * $this->dailyBonus->totalSince($since, $now));
            $sureRaises = $raises[$manager->id]['sure'] ?? 0;
            $snapshot = $manager->id === $connectedId ? $connectedSnapshot : null;
            $real = $snapshot instanceof ManagerBalanceSnapshot
                ? $snapshot->money
                    + ($this->activitySums($season, $snapshot->captured_at)[$manager->id] ?? 0)
                    - intdiv($this->raiseDetector->sureRaisedAfter($manager->id, $snapshot->captured_at), 2)
                : null;

            $balances[$manager->id] = new ManagerBalance(
                seasonManagerId: $manager->id,
                activity: $activityCash,
                dailyBonus: $dailyBonus,
                sureRaises: $sureRaises,
                possibleRaises: $raises[$manager->id]['possible'] ?? 0,
                real: $real,
                squadValue: (int) ($squad[$manager->id] ?? 0),
                residual: $real === null ? null : $real - ($activityCash + $dailyBonus - intdiv($sureRaises, 2)),
            );
        }

        return $balances;
    }

    /** The manager whose real cash we see: the owner of the latest snapshot this season. */
    public function connectedManagerId(Season $season): ?int
    {
        $managerId = ManagerBalanceSnapshot::query()
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->orderByDesc('captured_at')
            ->value('season_manager_id');

        return $managerId === null ? null : (int) $managerId;
    }

    /**
     * Signed money moves per manager, optionally only after a moment.
     *
     * @return array<int, int>
     */
    private function activitySums(Season $season, ?CarbonImmutable $after = null): array
    {
        $sums = [];

        Activity::query()
            ->where('season_id', $season->id)
            ->when($after, fn ($query) => $query->where('occurred_at', '>', $after))
            ->get(['type', 'source_season_manager_id', 'target_season_manager_id', 'amount'])
            ->each(function (Activity $activity) use (&$sums): void {
                $amount = (int) $activity->amount;
                $source = $activity->source_season_manager_id;

                match ($activity->type) {
                    SeasonActivityType::Sale, SeasonActivityType::WeeklyPrize => $sums[$source] = ($sums[$source] ?? 0) + $amount,
                    SeasonActivityType::Signing => $sums[$source] = ($sums[$source] ?? 0) - $amount,
                    SeasonActivityType::Buyout => $this->applyBuyout($sums, $source, $activity->target_season_manager_id, $amount),
                    default => null,
                };
            });

        return $sums;
    }

    /**
     * @param  array<int, int>  $sums
     */
    private function applyBuyout(array &$sums, int $payerId, ?int $receiverId, int $amount): void
    {
        $sums[$payerId] = ($sums[$payerId] ?? 0) - $amount;

        if ($receiverId !== null) {
            $sums[$receiverId] = ($sums[$receiverId] ?? 0) + $amount;
        }
    }

    /**
     * @return array<int, int> season manager id => sum of current market values
     */
    private function squadValues(Season $season): array
    {
        return ManagerPlayer::query()
            ->join('season_managers', 'season_managers.id', '=', 'manager_players.season_manager_id')
            ->join('player_seasons', function ($join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'manager_players.player_id')
                    ->where('player_seasons.season_id', '=', $season->id);
            })
            ->where('season_managers.season_id', $season->id)
            ->groupBy('manager_players.season_manager_id')
            ->selectRaw('manager_players.season_manager_id as manager_id, SUM(player_seasons.market_value) as total')
            ->pluck('total', 'manager_id')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
