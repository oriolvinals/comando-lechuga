<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;

trait AttachesNextFixtures
{
    /**
     * Attaches each player's next 3 upcoming fixtures for their team, soonest
     * first — only fixtures that haven't started yet (state=Scheduled), never
     * a live or finished one. Null-padded at the end, mirroring
     * attachRecentScores(), when fewer than 3 remain on the calendar.
     *
     * Out-of-league players always get 3 nulls without querying anything for
     * them — nobody needs their next match.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachNextFixtures(Collection $players, Season $season): void
    {
        $eligiblePlayers = $players->filter(
            fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague,
        );
        $teamIds = $eligiblePlayers->pluck('team_id')->unique()->all();

        /** @var array<int, list<Fixture>> $fixturesByTeam */
        $fixturesByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds))
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get()
            ->each(function (Fixture $fixture) use ($teamIds, &$fixturesByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true)) {
                        $fixturesByTeam[$teamId][] = $fixture;
                    }
                }
            });

        $players->each(function (Player $player) use ($fixturesByTeam): void {
            if ($player->status === PlayerStatus::OutOfLeague) {
                $player->next_fixtures = [null, null, null];

                return;
            }

            $slots = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->map(fn (Fixture $fixture): array => [
                    'week_number' => $fixture->week_number,
                    'opponent' => $fixture->team_local_id === $player->team_id
                        ? $fixture->guestTeam
                        : $fixture->localTeam,
                    'is_home' => $fixture->team_local_id === $player->team_id,
                ])
                ->values()
                ->all();

            /** @var array<int, array{week_number: int, opponent: Team, is_home: bool}|null> $paddedSlots */
            $paddedSlots = array_pad($slots, 3, null);

            $player->next_fixtures = $paddedSlots;
        });
    }
}
