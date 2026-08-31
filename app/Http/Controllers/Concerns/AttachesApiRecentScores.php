<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Http\Resources\TeamResource;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Support\Collection;

trait AttachesApiRecentScores
{
    /**
     * Same source data as AttachesRecentScores (the web trait), reshaped for
     * the API: a variable-length list (0-3 entries, oldest first) instead of
     * a null-padded fixed-length array — every entry here is a real finished
     * match, so there's no "empty slot" to represent.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachApiRecentScores(Collection $players, Season $season): void
    {
        $playerIds = $players->pluck('id')->all();
        $teamIds = $players->pluck('team_id')->unique()->all();

        /** @var array<int, list<Fixture>> $fixturesByTeam */
        $fixturesByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds))
            ->with(['localTeam', 'guestTeam'])
            ->get(['id', 'week_number', 'date', 'team_local_id', 'team_guest_id'])
            ->each(function (Fixture $fixture) use ($teamIds, &$fixturesByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true)) {
                        $fixturesByTeam[$teamId][] = $fixture;
                    }
                }
            });

        $scoresByPlayer = FixtureLineup::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->get(['player_id', 'fixture_id', 'fantasy_points'])
            ->groupBy('player_id')
            ->map(fn (Collection $rows) => $rows->keyBy('fixture_id'));

        $players->each(function (Player $player) use ($fixturesByTeam, $scoresByPlayer): void {
            $recentFixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortByDesc(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->values();

            $playerScores = $scoresByPlayer->get($player->id) ?? collect();

            $player->api_recent_scores = $recentFixtures
                ->map(fn (Fixture $fixture): array => [
                    'week_number' => $fixture->week_number,
                    'opponent' => (new TeamResource($fixture->team_local_id === $player->team_id
                        ? $fixture->guestTeam
                        : $fixture->localTeam))->resolve(),
                    'points' => $playerScores->get($fixture->id)?->fantasy_points,
                ])
                ->values()
                ->all();
        });
    }
}
