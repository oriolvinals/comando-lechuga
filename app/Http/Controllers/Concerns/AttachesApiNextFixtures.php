<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Resources\TeamResource;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Services\MatchDifficulty;
use App\Services\MatchDifficultyResult;
use Illuminate\Support\Collection;

trait AttachesApiNextFixtures
{
    /**
     * Same source data as AttachesNextFixtures (the web trait), reshaped for
     * the API:
     * - a variable-length list (0–3 entries, soonest first) instead of a
     *   null-padded fixed-length array;
     * - each entry carries the fixture's id and date, the rival's current
     *   real-table position, and its difficulty on MatchDifficulty's 0–10
     *   scale (10 = hardest) with the variant the player's position faces
     *   (DifficultyVariant::forPosition), in a single forMany() call for
     *   every player on the page — mirroring AttachesNextFixtures;
     * - a match MatchDifficulty can't rate (fixture with no date, or the
     *   team outside it) gets nulls rather than a made-up mid-table rating.
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

        /** @var array<int, list<Fixture>> $playerFixtures keyed by player id */
        $playerFixtures = [];

        /** @var list<array{0: Fixture, 1: int, 2: DifficultyVariant}> $items */
        $items = [];

        $eligiblePlayers->each(function (Player $player) use ($fixturesByTeam, &$playerFixtures, &$items): void {
            $variant = DifficultyVariant::forPosition($player->position);

            $fixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->values();

            $playerFixtures[$player->id] = $fixtures->all();

            foreach ($fixtures as $fixture) {
                $items[] = [$fixture, $player->team_id, $variant];
            }
        });

        $results = $items === [] ? [] : app(MatchDifficulty::class)->forMany($items);
        $offset = 0;

        $players->each(function (Player $player) use (&$offset, $playerFixtures, $results): void {
            if ($player->status === PlayerStatus::OutOfLeague) {
                $player->api_next_fixtures = [];

                return;
            }

            $slots = [];

            foreach ($playerFixtures[$player->id] ?? [] as $fixture) {
                $slots[] = $this->apiNextFixtureSlot($fixture, $player->team_id, $results[$offset] ?? null);
                $offset++;
            }

            $player->api_next_fixtures = $slots;
        });
    }

    /**
     * One upcoming fixture seen from `$teamId`'s side, with the difficulty
     * spread from `$result` — a single MatchDifficulty::forMany() entry
     * computed by the caller, in the same order as the fixtures it passed.
     * A null `$result` carries through as `difficulty`, `difficulty_variant`
     * and `rival_position` all null.
     *
     * @return array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, rival_position: int|null, difficulty: float|null, difficulty_variant: string|null}
     */
    private function apiNextFixtureSlot(Fixture $fixture, int $teamId, ?MatchDifficultyResult $result): array
    {
        $isHome = $fixture->team_local_id === $teamId;
        $opponent = $isHome ? $fixture->guestTeam : $fixture->localTeam;

        return [
            'fixture_id' => $fixture->id,
            'week_number' => $fixture->week_number,
            'date' => $fixture->date->toIso8601String(),
            'opponent' => (new TeamResource($opponent))->resolve(),
            'is_home' => $isHome,
            'rival_position' => $result?->rivalPosition,
            'difficulty' => $result?->difficulty,
            'difficulty_variant' => $result?->variant->value,
        ];
    }
}
