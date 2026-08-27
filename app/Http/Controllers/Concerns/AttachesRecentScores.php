<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use Illuminate\Support\Collection;

trait AttachesRecentScores
{
    /**
     * Attaches each player's points for their team's last 3 finished matches (oldest
     * first, ordered by fixture date). Unlike a plain "last 3 PlayerScore rows" lookup,
     * this is based on the team's actual fixtures — a finished match the player wasn't
     * called up for still takes its slot in the sequence (with a null score), instead of
     * being silently skipped in favor of an older match. `recent_scores_finished` marks,
     * per slot, whether a real finished fixture exists there at all — false only means
     * "the team hasn't played that many matches yet", never "not called up".
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

        /** @var array<int, Collection<int, Fixture>> $fixturesByTeam */
        $fixturesByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->where(fn ($query) => $query
                ->whereIn('team_local_id', $teamIds)
                ->orWhereIn('team_guest_id', $teamIds))
            ->get(['id', 'week_number', 'date', 'team_local_id', 'team_guest_id'])
            ->each(function (Fixture $fixture) use ($teamIds, &$fixturesByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (in_array($teamId, $teamIds, true)) {
                        $fixturesByTeam[$teamId][] = $fixture;
                    }
                }
            });

        $scoresByPlayer = PlayerScore::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->get(['player_id', 'fixture_id', 'points'])
            ->groupBy('player_id')
            ->map(fn (Collection $rows) => $rows->keyBy('fixture_id'));

        $usedWeeksByPlayer = $seasonManagerId === null
            ? collect()
            : ManagerLineupPlayer::query()
                ->whereIn('player_id', $playerIds)
                ->whereHas('lineup', fn ($query) => $query->where('season_manager_id', $seasonManagerId))
                ->with('lineup:id,week_number')
                ->get()
                ->groupBy('player_id')
                ->map(fn (Collection $rows) => $rows->pluck('lineup.week_number')->all());

        $players->each(function (Player $player) use ($fixturesByTeam, $scoresByPlayer, $usedWeeksByPlayer, $seasonManagerId): void {
            $recentFixtures = collect($fixturesByTeam[$player->team_id] ?? [])
                ->sortByDesc(fn (Fixture $fixture) => $fixture->date)
                ->take(3)
                ->sortBy(fn (Fixture $fixture) => $fixture->date)
                ->values();

            $playerScores = $scoresByPlayer->get($player->id) ?? collect();

            $points = $recentFixtures
                ->map(fn (Fixture $fixture): ?int => $playerScores->get($fixture->id)?->points)
                ->all();
            $finished = array_fill(0, count($points), true);

            /** @var array<int, int|null> $paddedPoints */
            $paddedPoints = array_pad($points, 3, null);

            /** @var array<int, bool> $paddedFinished */
            $paddedFinished = array_pad($finished, 3, false);

            $player->recent_scores = $paddedPoints;
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
