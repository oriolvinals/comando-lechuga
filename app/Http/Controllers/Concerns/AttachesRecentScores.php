<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
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
     * @param  Collection<int, Player>  $players
     */
    private function attachRecentScores(Collection $players, Season $season): void
    {
        $playerIds = $players->pluck('id')->all();

        $scoresByPlayer = PlayerScore::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with('fixture:id,date')
            ->get()
            ->groupBy('player_id');

        $players->each(function (Player $player) use ($scoresByPlayer): void {
            $points = ($scoresByPlayer->get($player->id) ?? collect())
                ->sortByDesc(fn (PlayerScore $score) => $score->fixture->date)
                ->take(3)
                ->sortBy(fn (PlayerScore $score) => $score->fixture->date)
                ->values()
                ->map(fn (PlayerScore $score): int => $score->points)
                ->all();

            /** @var array<int, int|null> $padded */
            $padded = array_pad($points, 3, null);

            $player->recent_scores = $padded;
        });
    }
}
