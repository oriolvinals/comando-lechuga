<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Support\Collection;

trait FiltersSeasonWeeks
{
    /**
     * Week numbers considered started: every week before the current one
     * (trusted as past regardless of a stray unfinished fixture — e.g. one
     * postponed match shouldn't disqualify an otherwise-played jornada),
     * plus the current week only if at least one of its fixtures has
     * kicked off. Never a week after the current one.
     *
     * @return array<int, int>
     */
    private function startedWeekNumbers(Season $season): array
    {
        $weeks = $this->pastWeekNumbers($season);

        if ($this->currentWeekStarted($season)) {
            $weeks[] = $season->current_week;
        }

        return $weeks;
    }

    /**
     * Whether at least one fixture in the current week has kicked off. A
     * week being "current" (by date) doesn't mean it's actually started —
     * live-only data like `SeasonManager::live_points` needs this check
     * too, not just the date-based `current_week`, or it'd show a stale or
     * placeholder value before the jornada has really begun.
     */
    private function currentWeekStarted(Season $season): bool
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $season->current_week)
            ->where('state', '!=', FixtureState::Scheduled)
            ->exists();
    }

    /**
     * Whether the current jornada is actually in progress: kicked off, but
     * not yet finished. False both before it starts and after it finishes —
     * the latter matters because `current_week` doesn't advance to the next
     * jornada until the sync job runs, so a finished-but-not-yet-advanced
     * week must not still count as "live", or live-only data like
     * `SeasonManager::live_points` would duplicate a jornada already covered
     * by the finished-weeks data (e.g. `recent_form`).
     */
    private function currentWeekIsLive(Season $season): bool
    {
        return $this->currentWeekStarted($season)
            && !in_array($season->current_week, $this->finishedWeekNumbers($season), true);
    }

    /**
     * Week numbers considered finished: every week before the current one
     * (same trust-the-past reasoning as {@see startedWeekNumbers()}), plus
     * the current week only if all of its fixtures have finished. Never a
     * week after the current one.
     *
     * @return array<int, int>
     */
    private function finishedWeekNumbers(Season $season): array
    {
        $weeks = $this->pastWeekNumbers($season);

        $currentWeekFinished = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $season->current_week)
            ->where('state', '!=', FixtureState::Finished)
            ->doesntExist();

        if ($currentWeekFinished) {
            $weeks[] = $season->current_week;
        }

        return $weeks;
    }

    /**
     * @return array<int, int>
     */
    private function pastWeekNumbers(Season $season): array
    {
        $lastPastWeek = $season->current_week - 1;

        return $lastPastWeek >= 1 ? range(1, $lastPastWeek) : [];
    }

    /**
     * How far along each jornada is, for a week picker's coloring: 'none'
     * (no fixture finished yet), 'partial' (some but not all), or 'all'
     * (every fixture finished). Keyed by week number — cast to object at the
     * call site, since PHP normalizes a numeric string key back to int and
     * a plain array here could otherwise serialize as a sparse JSON array.
     *
     * @return array<int, 'none'|'partial'|'all'>
     */
    private function weekProgress(Season $season): array
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->get(['week_number', 'state'])
            ->groupBy('week_number')
            ->mapWithKeys(function (Collection $fixtures, int $weekNumber): array {
                $finished = $fixtures->where('state', FixtureState::Finished)->count();

                $status = match (true) {
                    $finished === 0 => 'none',
                    $finished === $fixtures->count() => 'all',
                    default => 'partial',
                };

                return [$weekNumber => $status];
            })
            ->all();
    }
}
