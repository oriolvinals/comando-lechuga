<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Resources\TeamResource;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Support\Collection;

trait AttachesApiNextFixtures
{
    /**
     * Same source data as AttachesNextFixtures (the web trait), reshaped for
     * the API: a variable-length list (0-3 entries, soonest first) instead
     * of a null-padded fixed-length array.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachApiNextFixtures(Collection $players, Season $season): void
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
                $player->api_next_fixtures = [];

                return;
            }

            $player->api_next_fixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->map(fn (Fixture $fixture): array => [
                    'week_number' => $fixture->week_number,
                    'opponent' => (new TeamResource($fixture->team_local_id === $player->team_id
                        ? $fixture->guestTeam
                        : $fixture->localTeam))->resolve(),
                    'is_home' => $fixture->team_local_id === $player->team_id,
                ])
                ->values()
                ->all();
        });
    }
}
