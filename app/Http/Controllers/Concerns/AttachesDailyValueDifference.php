<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

trait AttachesDailyValueDifference
{
    /**
     * Attaches how much each manager's current squad gained or lost in the
     * latest daily market update: the sum of its players' daily value
     * differences. A manager without players gets 0.
     *
     * @param  Collection<int, SeasonManager>  $seasonManagers
     */
    private function attachDailyValueDifference(Collection $seasonManagers, Season $season): void
    {
        $differences = ManagerPlayer::query()
            ->join('player_seasons', function (JoinClause $join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'manager_players.player_id')
                    ->where('player_seasons.season_id', $season->id);
            })
            ->whereIn('manager_players.season_manager_id', $seasonManagers->pluck('id'))
            ->groupBy('manager_players.season_manager_id')
            ->selectRaw('manager_players.season_manager_id, SUM(player_seasons.market_value_difference) as daily_value_difference')
            ->pluck('daily_value_difference', 'season_manager_id');

        $seasonManagers->each(function (SeasonManager $manager) use ($differences): void {
            $manager->daily_value_difference = (int) ($differences->get($manager->id) ?? 0);
        });
    }
}
