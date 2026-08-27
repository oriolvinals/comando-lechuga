<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\Activity;
use App\Models\PlayerMarket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait AttachesActivityValueDifference
{
    /**
     * @param  Collection<int, Activity>  $activities
     */
    private function attachValueDifferences(Collection $activities): void
    {
        $eligible = $activities->filter(
            fn (Activity $activity): bool => $activity->player_id !== null && $activity->amount !== null,
        );

        $marketValues = $this->marketValuesByPlayerAndDate($eligible);

        $activities->each(function (Activity $activity) use ($marketValues): void {
            $playerId = $activity->player_id;
            $amount = $activity->amount;

            if ($playerId === null || $amount === null) {
                $activity->value_difference = null;

                return;
            }

            $market = $marketValues->get($playerId.'|'.$activity->occurred_at->toDateString());
            $activity->value_difference = $market !== null ? $amount - $market->value : null;
        });
    }

    /**
     * @param  Collection<int, Activity>  $eligible
     * @return Collection<string, PlayerMarket>
     */
    private function marketValuesByPlayerAndDate(Collection $eligible): Collection
    {
        if ($eligible->isEmpty()) {
            return collect();
        }

        return PlayerMarket::query()
            ->where(function (Builder $query) use ($eligible): void {
                foreach ($eligible as $activity) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('player_id', $activity->player_id)
                        ->whereDate('date', $activity->occurred_at->toDateString()));
                }
            })
            ->get()
            ->keyBy(fn (PlayerMarket $market): string => $market->player_id.'|'.$market->date->toDateString());
    }
}
