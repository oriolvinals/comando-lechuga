<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;

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

        $currentWeekStarted = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $season->current_week)
            ->where('state', '!=', FixtureState::Scheduled)
            ->exists();

        if ($currentWeekStarted) {
            $weeks[] = $season->current_week;
        }

        return $weeks;
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
}
