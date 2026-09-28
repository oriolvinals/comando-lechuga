<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Season;
use Carbon\CarbonImmutable;

/**
 * How many of the jornada's shields (blindajes) each manager has used. Every
 * manager gets 2 per jornada; unused ones are lost when the jornada ends.
 *
 * The count is rebuilt from the league's activity. The allowance renews when
 * the league pays the previous jornada's prizes (`weekly_prize`, around 04:30
 * the morning after the jornada ends). So a shield belongs to the first
 * jornada whose prize was paid at or after it; one made after the last
 * payout belongs to the current shield jornada, the one after it.
 *
 * @phpstan-type ShieldLedger array{current_week: int, used: array<int, array<int, int>>}
 * @phpstan-type ShieldSummary array{week_number: int, used: int, remaining: int, total: int}
 */
class ManagerShields
{
    public const int PER_WEEK = 2;

    /**
     * The current shield jornada and, per manager id, the shields used in
     * each jornada up to it (week number => count; jornadas without shields
     * are missing). Two queries, whatever the number of managers.
     *
     * @return ShieldLedger
     */
    public function forSeason(Season $season): array
    {
        $prizesPaidAt = $this->prizesPaidAt($season);
        $currentWeek = min(
            max(1, $season->total_weeks),
            $prizesPaidAt === [] ? 1 : array_key_last($prizesPaidAt) + 1,
        );
        $used = [];

        Activity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::Shield)
            ->get(['source_season_manager_id', 'occurred_at'])
            ->each(function (Activity $shield) use ($prizesPaidAt, $currentWeek, &$used): void {
                $weekNumber = $this->weekOf($shield->occurred_at, $prizesPaidAt) ?? $currentWeek;
                $managerId = $shield->source_season_manager_id;

                $used[$managerId][$weekNumber] = ($used[$managerId][$weekNumber] ?? 0) + 1;
            });

        return ['current_week' => $currentWeek, 'used' => $used];
    }

    /**
     * The manager's shields in the current shield jornada.
     *
     * @param  ShieldLedger  $ledger
     * @return ShieldSummary
     */
    public static function current(array $ledger, int $seasonManagerId): array
    {
        return self::summary($ledger['current_week'], $ledger['used'][$seasonManagerId][$ledger['current_week']] ?? 0);
    }

    /**
     * The manager's shields in the given jornada; null for a jornada after
     * the current shield jornada.
     *
     * @param  ShieldLedger  $ledger
     * @return ShieldSummary|null
     */
    public static function inWeek(array $ledger, int $seasonManagerId, int $weekNumber): ?array
    {
        if ($weekNumber < 1 || $weekNumber > $ledger['current_week']) {
            return null;
        }

        return self::summary($weekNumber, $ledger['used'][$seasonManagerId][$weekNumber] ?? 0);
    }

    /**
     * Shields used per jornada, from the first one to the current shield
     * jornada, with 0 for jornadas without any.
     *
     * @param  ShieldLedger  $ledger
     * @return array<int, int> week number => used
     */
    public static function usedByWeek(array $ledger, int $seasonManagerId): array
    {
        $usedByWeek = [];

        foreach (range(1, $ledger['current_week']) as $weekNumber) {
            $usedByWeek[$weekNumber] = $ledger['used'][$seasonManagerId][$weekNumber] ?? 0;
        }

        return $usedByWeek;
    }

    /**
     * @return ShieldSummary
     */
    public static function summary(int $weekNumber, int $used): array
    {
        return [
            'week_number' => $weekNumber,
            'used' => $used,
            'remaining' => max(0, self::PER_WEEK - $used),
            'total' => self::PER_WEEK,
        ];
    }

    /**
     * When each jornada's prizes were paid: the earliest `weekly_prize` of
     * that week, ordered by week number.
     *
     * @return array<int, CarbonImmutable> week number => paid at
     */
    private function prizesPaidAt(Season $season): array
    {
        $paidAt = [];

        Activity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::WeeklyPrize)
            ->whereNotNull('week_number')
            ->get(['week_number', 'occurred_at'])
            ->each(function (Activity $prize) use (&$paidAt): void {
                $weekNumber = (int) $prize->week_number;

                if (!isset($paidAt[$weekNumber]) || $prize->occurred_at->lessThan($paidAt[$weekNumber])) {
                    $paidAt[$weekNumber] = $prize->occurred_at;
                }
            });

        ksort($paidAt);

        return $paidAt;
    }

    /**
     * The first jornada whose prize was paid at or after `$moment`; null
     * when every known payout came before it.
     *
     * @param  array<int, CarbonImmutable>  $prizesPaidAt
     */
    private function weekOf(CarbonImmutable $moment, array $prizesPaidAt): ?int
    {
        foreach ($prizesPaidAt as $weekNumber => $paidAt) {
            if ($moment->lessThanOrEqualTo($paidAt)) {
                return $weekNumber;
            }
        }

        return null;
    }
}
