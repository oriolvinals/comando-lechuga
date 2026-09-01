<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\Season;
use Illuminate\Database\Eloquent\Collection;

trait AttachesLineupFixtures
{
    /**
     * Attaches the Fixture each lineup player's team played that lineup's
     * week, so the frontend can show the match result/ficha alongside a
     * player's jornada stats. Resolved by team_id + week_number, the same
     * way attachMatchFinished() finds it — not via ManagerLineupPlayer::
     * fixture_id, which isn't always resolved (see AttachesLineupPlayerScores).
     *
     * @param  Collection<int, ManagerLineup>  $lineups
     */
    private function attachLineupFixtures(Collection $lineups, Season $season): void
    {
        $weekNumbers = $lineups->pluck('week_number')->unique();

        /** @var array<int, array<int, Fixture>> $fixturesByWeekAndTeam */
        $fixturesByWeekAndTeam = Fixture::query()
            ->where('season_id', $season->id)
            ->whereIn('week_number', $weekNumbers)
            ->with(['localTeam', 'guestTeam'])
            ->get()
            ->reduce(function (array $carry, Fixture $fixture): array {
                $carry[$fixture->week_number][$fixture->team_local_id] = $fixture;
                $carry[$fixture->week_number][$fixture->team_guest_id] = $fixture;

                return $carry;
            }, []);

        $lineups->each(function (ManagerLineup $lineup) use ($fixturesByWeekAndTeam): void {
            foreach ($lineup->players as $entry) {
                $entry->fixture = $fixturesByWeekAndTeam[$lineup->week_number][$entry->player->team_id] ?? null;
            }
        });
    }
}
