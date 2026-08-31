<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\Season;
use Illuminate\Database\Eloquent\Collection;

trait AttachesMatchFinished
{
    /**
     * A lineup player's `points` is null both when their team hasn't played
     * yet and when they weren't called up for a match that already finished
     * — the frontend needs to tell those apart. Sets `match_finished` on
     * each lineup player entry based on whether their team's fixture for
     * that lineup's week has finished.
     *
     * @param  Collection<int, ManagerLineup>  $lineupHistory
     */
    private function attachMatchFinished(Collection $lineupHistory, Season $season): void
    {
        /** @var array<int, array<int, true>> $finishedTeamWeeks */
        $finishedTeamWeeks = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->get(['week_number', 'team_local_id', 'team_guest_id'])
            ->reduce(function (array $carry, Fixture $fixture): array {
                $carry[$fixture->week_number][$fixture->team_local_id] = true;
                $carry[$fixture->week_number][$fixture->team_guest_id] = true;

                return $carry;
            }, []);

        $lineupHistory->each(function (ManagerLineup $lineup) use ($finishedTeamWeeks): void {
            foreach ($lineup->players as $entry) {
                $entry->match_finished = $finishedTeamWeeks[$lineup->week_number][$entry->player->team_id] ?? false;
            }
        });
    }
}
