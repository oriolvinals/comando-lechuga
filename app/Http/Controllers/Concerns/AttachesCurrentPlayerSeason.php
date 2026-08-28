<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Player;
use App\Models\PlayerSeason;
use Illuminate\Support\Collection;

trait AttachesCurrentPlayerSeason
{
    /**
     * @param  Collection<int, Player>  $players
     */
    private function attachCurrentSeason(Collection $players, int $seasonId): void
    {
        $playerIds = $players->pluck('id')->all();

        $playerSeasons = PlayerSeason::query()
            ->where('season_id', $seasonId)
            ->whereIn('player_id', $playerIds)
            ->get()
            ->keyBy('player_id');

        $players->each(function (Player $player) use ($playerSeasons): void {
            $playerSeason = $playerSeasons->get($player->id);

            if ($playerSeason === null) {
                return;
            }

            $player->position = $playerSeason->position;
            $player->market_value = $playerSeason->market_value;
            $player->market_value_difference = $playerSeason->market_value_difference;
            $player->points = $playerSeason->points;
            $player->average_points = $playerSeason->average_points;
            $player->syncOriginal();
        });
    }
}
