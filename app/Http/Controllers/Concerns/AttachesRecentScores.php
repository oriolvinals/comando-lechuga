<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\SeasonManagerLineupPlayer;
use Illuminate\Support\Collection;

trait AttachesRecentScores
{
    /**
     * Attaches each player's points for their last 3 played matches (oldest first,
     * left to right), ordered by fixture date — every fixture a player's team plays
     * produces a PlayerScore row (even a benched player scores 0), so this is never
     * sparse because of a skipped jornada. It only comes back shorter than 3 — padded
     * with null at the end — for a player without 3 matches of history yet.
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

        $scoresByPlayer = PlayerScore::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with('fixture:id,date,week_number')
            ->get()
            ->groupBy('player_id');

        $usedWeeksByPlayer = $seasonManagerId === null
            ? collect()
            : SeasonManagerLineupPlayer::query()
                ->whereIn('player_id', $playerIds)
                ->whereHas('lineup', fn ($query) => $query->where('season_manager_id', $seasonManagerId))
                ->with('lineup:id,week_number')
                ->get()
                ->groupBy('player_id')
                ->map(fn (Collection $rows) => $rows->pluck('lineup.week_number')->all());

        $players->each(function (Player $player) use ($scoresByPlayer, $usedWeeksByPlayer, $seasonManagerId): void {
            $recentScores = ($scoresByPlayer->get($player->id) ?? collect())
                ->sortByDesc(fn (PlayerScore $score) => $score->fixture->date)
                ->take(3)
                ->sortBy(fn (PlayerScore $score) => $score->fixture->date)
                ->values();

            $points = $recentScores->map(fn (PlayerScore $score): int => $score->points)->all();

            /** @var array<int, int|null> $paddedPoints */
            $paddedPoints = array_pad($points, 3, null);
            $player->recent_scores = $paddedPoints;

            if ($seasonManagerId === null) {
                return;
            }

            $usedWeeks = $usedWeeksByPlayer->get($player->id, []);
            $used = $recentScores
                ->map(fn (PlayerScore $score): bool => in_array($score->fixture->week_number, $usedWeeks, true))
                ->all();

            /** @var array<int, bool|null> $paddedUsed */
            $paddedUsed = array_pad($used, 3, null);
            $player->recent_scores_used = $paddedUsed;
        });
    }
}
