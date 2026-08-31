<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\ManagerPlayer;
use App\Models\Player;
use Illuminate\Support\Collection;

trait AttachesOwnerManager
{
    /**
     * @param  Collection<int, Player>  $players
     */
    private function attachOwnerManager(Collection $players, int $seasonId): void
    {
        $playerIds = $players->pluck('id')->all();

        $owners = ManagerPlayer::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $seasonId))
            ->with('seasonManager')
            ->get()
            ->keyBy('player_id');

        $players->each(function (Player $player) use ($owners): void {
            $seasonManager = $owners->get($player->id)?->seasonManager;

            $player->owner_manager = $seasonManager === null ? null : [
                'id' => $seasonManager->id,
                'name' => $seasonManager->name,
                'logo' => $seasonManager->logo ? asset($seasonManager->logo) : '',
                'primary_color' => $seasonManager->primary_color,
            ];
        });
    }
}
