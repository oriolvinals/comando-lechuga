<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use App\Services\LeagueStandings;
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

        $positions = $fixturesByTeam === [] ? [] : $this->standingsPositions($season);

        $players->each(function (Player $player) use ($fixturesByTeam, $positions): void {
            if ($player->status === PlayerStatus::OutOfLeague) {
                $player->next_fixtures = [null, null, null];

                return;
            }

            $slots = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->map(fn (Fixture $fixture): array => $this->nextFixtureSlot($fixture, $player->team_id, $positions))
                ->values()
                ->all();

            /** @var array<int, array{week_number: int, opponent: Team, is_home: bool, rival_position: int, difficulty: float}|null> $paddedSlots */
            $paddedSlots = array_pad($slots, 3, null);

            $player->next_fixtures = $paddedSlots;
        });
    }

    /**
     * The current real LaLiga table as each season team's position, keyed by
     * team id — what nextFixtureSlot() rates each rival against.
     *
     * @return array<int, int>
     */
    private function standingsPositions(Season $season): array
    {
        return app(LeagueStandings::class)->positions($season);
    }

    /**
     * One upcoming fixture seen from `$teamId`'s side, with the rival's
     * current standings position and its difficulty (−1 leader … +1 last,
     * the same scale the max bid model uses). A rival missing from the table
     * counts as mid-table.
     *
     * @param  array<int, int>  $positions  from standingsPositions()
     * @return array{week_number: int, opponent: Team, is_home: bool, rival_position: int, difficulty: float}
     */
    private function nextFixtureSlot(Fixture $fixture, int $teamId, array $positions): array
    {
        $isHome = $fixture->team_local_id === $teamId;
        $opponent = $isHome ? $fixture->guestTeam : $fixture->localTeam;
        $teamCount = count($positions);
        $rivalPosition = $positions[$opponent->id] ?? intdiv($teamCount + 1, 2);

        return [
            'week_number' => $fixture->week_number,
            'opponent' => $opponent,
            'is_home' => $isHome,
            'rival_position' => $rivalPosition,
            'difficulty' => round(LeagueStandings::difficulty($rivalPosition, $teamCount), 3),
        ];
    }
}
