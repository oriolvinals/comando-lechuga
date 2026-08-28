<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Player;
use App\Models\PlayerSeason;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

trait AttachesCurrentPlayerSeason
{
    /**
     * @param  Collection<int, Player>|LengthAwarePaginator<int, Player>  $players
     */
    private function attachCurrentSeason(Collection|LengthAwarePaginator $players, int $seasonId): void
    {
        $entries = $players instanceof LengthAwarePaginator ? $players->getCollection() : $players;
        $playerIds = $entries->pluck('id')->all();

        $seasons = PlayerSeason::query()
            ->where('season_id', $seasonId)
            ->whereIn('player_id', $playerIds)
            ->get()
            ->keyBy('player_id');

        $entries->each(function (Player $player) use ($seasons): void {
            $season = $seasons->get($player->id);

            if ($season === null) {
                return;
            }

            $player->position = $season->position;
            $player->market_value = $season->market_value;
            $player->market_value_difference = $season->market_value_difference;
            $player->points = $season->points;
            $player->average_points = $season->average_points;
        });
    }
}
