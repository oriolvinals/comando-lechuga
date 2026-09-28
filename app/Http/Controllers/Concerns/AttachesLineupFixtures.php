<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
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
     * A team with a postponed match and its rescheduled replacement in the
     * same week gets the replacement. Otherwise the latest fixture (by date,
     * then id) wins, so the choice is deterministic.
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
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->reduce(function (array $carry, Fixture $fixture): array {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    $current = $carry[$fixture->week_number][$teamId] ?? null;

                    if ($current === null || ($current->state === FixtureState::Postponed && $fixture->state !== FixtureState::Postponed)) {
                        $carry[$fixture->week_number][$teamId] = $fixture;
                    }
                }

                return $carry;
            }, []);

        $lineups->each(function (ManagerLineup $lineup) use ($fixturesByWeekAndTeam): void {
            foreach ($lineup->players as $entry) {
                $entry->fixture = $fixturesByWeekAndTeam[$lineup->week_number][$entry->player->team_id] ?? null;
            }
        });
    }
}
