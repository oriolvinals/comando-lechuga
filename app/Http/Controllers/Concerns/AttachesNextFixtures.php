<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use App\Services\MatchDifficulty;
use App\Services\MatchDifficultyResult;
use Illuminate\Support\Collection;

trait AttachesNextFixtures
{
    /**
     * The difficulty keys a slot carries when its MatchDifficultyResult is
     * null (the fixture or team fell outside MatchDifficulty::for()'s reach)
     * — see nextFixtureSlot().
     *
     * @var array{difficulty: null, difficulty_variant: null, difficulty_components: array{}, absence_adjusted: null, rival_position: null}
     */
    private const array NULL_DIFFICULTY = [
        'difficulty' => null,
        'difficulty_variant' => null,
        'difficulty_components' => [],
        'absence_adjusted' => null,
        'rival_position' => null,
    ];

    /**
     * Attaches each player's next $count (3 by default) upcoming fixtures for
     * their team, soonest first — only fixtures that haven't started yet
     * (state=Scheduled), never a live or finished one. Null-padded at the end,
     * mirroring attachRecentScores(), when fewer remain on the calendar.
     *
     * Out-of-league players always get $count nulls without querying anything
     * for them — nobody needs their next match. Each remaining player's
     * fixtures are rated by MatchDifficulty using the variant their position
     * faces (DifficultyVariant::forPosition), in a single forMany() call for
     * every player on the page. With `$fromWeek`, only fixtures of that
     * jornada or a later one count (the comparator leaves a pending match of
     * a live jornada in its past columns).
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachNextFixtures(Collection $players, Season $season, int $count = 3, ?int $fromWeek = null): void
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
            ->when($fromWeek !== null, fn ($query) => $query->where('week_number', '>=', $fromWeek))
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

        $eligiblePlayers->each(function (Player $player) use ($fixturesByTeam, $count, &$playerFixtures, &$items): void {
            $variant = DifficultyVariant::forPosition($player->position);

            $fixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->take($count)
                ->values();

            $playerFixtures[$player->id] = $fixtures->all();

            foreach ($fixtures as $fixture) {
                $items[] = [$fixture, $player->team_id, $variant];
            }
        });

        $results = $items === [] ? [] : app(MatchDifficulty::class)->forMany($items);
        $offset = 0;

        $players->each(function (Player $player) use (&$offset, $playerFixtures, $results, $count): void {
            if ($player->status === PlayerStatus::OutOfLeague) {
                $player->next_fixtures = array_fill(0, $count, null);

                return;
            }

            $slots = [];

            foreach ($playerFixtures[$player->id] ?? [] as $fixture) {
                $slots[] = $this->nextFixtureSlot($fixture, $player->team_id, $results[$offset] ?? null);
                $offset++;
            }

            $player->next_fixtures = array_pad($slots, $count, null);
        });
    }

    /**
     * One upcoming fixture seen from `$teamId`'s side, with the difficulty
     * spread from `$result` — a single MatchDifficulty::forMany() entry
     * computed by the caller, in the same order as the fixtures it passed.
     * A null `$result` (fixture with no date, `$teamId` outside it or an unrated rival —
     * MatchDifficulty::for()'s own null cases) carries through as
     * `difficulty: null` and the other difficulty keys null/empty.
     *
     * @return array{week_number: int, opponent: Team, is_home: bool, date: string, difficulty: float|null, difficulty_variant: string|null, difficulty_components: array{rival_strength: float, home: float, absences: float}|array{}, absence_adjusted: bool|null, rival_position: int|null}
     */
    private function nextFixtureSlot(Fixture $fixture, int $teamId, ?MatchDifficultyResult $result): array
    {
        $isHome = $fixture->team_local_id === $teamId;
        $opponent = $isHome ? $fixture->guestTeam : $fixture->localTeam;

        return [
            'week_number' => $fixture->week_number,
            'opponent' => $opponent,
            'is_home' => $isHome,
            'date' => $fixture->date->toIso8601String(),
            ...($result?->toArray() ?? self::NULL_DIFFICULTY),
        ];
    }
}
