<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;

trait AttachesRecentScores
{
    /**
     * Attaches each player's points for their team's last 3 finished matches (oldest
     * first, ordered by fixture date). Unlike a plain "last 3 FixtureLineup rows" lookup,
     * this is based on the team's actual fixtures — a finished match the player wasn't
     * called up for still takes its slot in the sequence (with a null score), instead of
     * being silently skipped in favor of an older match. `recent_scores_finished` marks,
     * per slot, whether a real finished fixture exists there at all — false only means
     * "the team hasn't played that many matches yet", never "not called up".
     *
     * A finished match the player actually has a FixtureLineup for always takes its slot
     * too, even when it isn't one of the player's CURRENT team's fixtures — otherwise a
     * mid-season transfer makes a player's real recent appearances (for their old club)
     * silently read as "no jugó" once they're viewed on their new team's roster.
     *
     * When $seasonManagerId is given (the manager ficha, where "used by this manager" is
     * a meaningful question), also attaches `recent_scores_used`: for each of the same
     * 3 jornadas, whether the player was actually in that manager's lineup that week, as
     * opposed to scoring those points while benched or not yet owned.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachRecentScores(Collection $players, Season $season, ?int $seasonManagerId = null): void
    {
        $playerIds = $players->pluck('id')->all();
        $teamIds = $players->pluck('team_id')->unique()->all();

        $scoresByPlayer = FixtureLineup::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->get(['player_id', 'fixture_id', 'fantasy_points', 'team_id'])
            ->groupBy('player_id')
            ->map(fn (Collection $rows) => $rows->keyBy('fixture_id'));

        // Fixture ids a player has a FixtureLineup for — possibly for a club other than
        // their current one — need to be fetched even when that fixture isn't one of
        // $teamIds' own matches, so a transferred player's old-club appearances can still
        // fill a recent-scores slot.
        $ownFixtureIds = $scoresByPlayer
            ->flatMap(fn (Collection $rows) => $rows->keys())
            ->unique()
            ->all();

        /** @var array<int, Collection<int, Fixture>> $fixturesByTeam */
        $fixturesByTeam = [];
        /** @var array<int, Fixture> $fixturesById */
        $fixturesById = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds)
                ->orWhereIn('id', $ownFixtureIds))
            ->with(['localTeam', 'guestTeam'])
            ->get(['id', 'week_number', 'date', 'team_local_id', 'team_guest_id'])
            ->each(function (Fixture $fixture) use ($teamIds, &$fixturesByTeam, &$fixturesById): void {
                $fixturesById[$fixture->id] = $fixture;

                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true)) {
                        $fixturesByTeam[$teamId][] = $fixture;
                    }
                }
            });

        $usedWeeksByPlayer = $seasonManagerId === null
            ? collect()
            : ManagerLineupPlayer::query()
                ->whereIn('player_id', $playerIds)
                ->whereHas('lineup', fn ($query) => $query->where('season_manager_id', $seasonManagerId))
                ->with('lineup:id,week_number')
                ->get()
                ->groupBy('player_id')
                ->map(fn (Collection $rows) => $rows->pluck('lineup.week_number')->all());

        $players->each(function (Player $player) use ($fixturesByTeam, $fixturesById, $scoresByPlayer, $usedWeeksByPlayer, $seasonManagerId): void {
            $playerScores = $scoresByPlayer->get($player->id) ?? collect();

            // Keyed by week_number, not fixture id: a transferred player's old-club match
            // and their current team's own match for that same jornada are two different
            // fixtures, and only one slot may represent that jornada. The player's own
            // appearance always wins that slot over the current team's fixture.
            $candidateFixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->keyBy(fn (Fixture $fixture) => $fixture->week_number);

            foreach ($playerScores->keys() as $fixtureId) {
                if (isset($fixturesById[$fixtureId])) {
                    $fixture = $fixturesById[$fixtureId];
                    $candidateFixtures->put($fixture->week_number, $fixture);
                }
            }

            $recentFixtures = $candidateFixtures
                ->sortByDesc(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->values();

            $points = $recentFixtures
                ->map(fn (Fixture $fixture): ?int => $playerScores->get($fixture->id)?->fantasy_points)
                ->all();
            // The rival faced in that match — from the team the player actually turned
            // out for that jornada (FixtureLineup.team_id) when they have a score there,
            // since a transferred player's old-club match isn't played from the
            // perspective of their current team_id.
            $opponents = $recentFixtures
                ->map(function (Fixture $fixture) use ($player, $playerScores) {
                    $referenceTeamId = $playerScores->get($fixture->id)?->team_id ?? $player->team_id;

                    return $fixture->team_local_id === $referenceTeamId
                        ? $fixture->guestTeam
                        : $fixture->localTeam;
                })
                ->all();
            $finished = array_fill(0, count($points), true);

            /** @var array<int, int|null> $paddedPoints */
            $paddedPoints = array_pad($points, 3, null);

            /** @var array<int, Team|null> $paddedOpponents */
            $paddedOpponents = array_pad($opponents, 3, null);

            /** @var array<int, bool> $paddedFinished */
            $paddedFinished = array_pad($finished, 3, false);

            $player->recent_scores = $paddedPoints;
            $player->recent_scores_opponents = $paddedOpponents;
            $player->recent_scores_finished = $paddedFinished;

            if ($seasonManagerId === null) {
                return;
            }

            $usedWeeks = $usedWeeksByPlayer->get($player->id, []);
            $used = $recentFixtures
                ->map(fn (Fixture $fixture): bool => in_array($fixture->week_number, $usedWeeks, true))
                ->all();

            /** @var array<int, bool|null> $paddedUsed */
            $paddedUsed = array_pad($used, 3, null);
            $player->recent_scores_used = $paddedUsed;
        });
    }
}
