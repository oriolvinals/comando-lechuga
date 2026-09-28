<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Resources\TeamResource;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Services\LeagueStandings;
use Illuminate\Support\Collection;

trait AttachesApiNextFixtures
{
    /**
     * Same source data as AttachesNextFixtures (the web trait), reshaped for
     * the API:
     * - a variable-length list (0–3 entries, soonest first) instead of a
     *   null-padded fixed-length array;
     * - each entry carries the fixture's id and date, the rival's current
     *   real-table position and its difficulty (−1 leader … +1 last);
     * - a rival missing from the table gets nulls rather than a made-up
     *   mid-table rating.
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

        $positions = $fixturesByTeam === [] ? [] : app(LeagueStandings::class)->positions($season);
        $teamCount = count($positions);

        $players->each(function (Player $player) use ($fixturesByTeam, $positions, $teamCount): void {
            if ($player->status === PlayerStatus::OutOfLeague) {
                $player->api_next_fixtures = [];

                return;
            }

            $player->api_next_fixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->map(function (Fixture $fixture) use ($player, $positions, $teamCount): array {
                    $isHome = $fixture->team_local_id === $player->team_id;
                    $opponent = $isHome ? $fixture->guestTeam : $fixture->localTeam;
                    $rivalPosition = $positions[$opponent->id] ?? null;

                    return [
                        'fixture_id' => $fixture->id,
                        'week_number' => $fixture->week_number,
                        'date' => $fixture->date->toIso8601String(),
                        'opponent' => (new TeamResource($opponent))->resolve(),
                        'is_home' => $isHome,
                        'rival_position' => $rivalPosition,
                        'difficulty' => $rivalPosition === null
                            ? null
                            : round(LeagueStandings::difficulty($rivalPosition, $teamCount), 3),
                    ];
                })
                ->values()
                ->all();
        });
    }
}
