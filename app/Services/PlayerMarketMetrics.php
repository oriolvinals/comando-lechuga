<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerSeason;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Market-derived figures for the player ficha: the 30-day value multiple,
 * season points per million of value (with its league rank) and the current
 * owner's paper gain over what they paid.
 */
class PlayerMarketMetrics
{
    public const int VALUE_TREND_DAYS = 30;

    /**
     * Current value ÷ the value on the last snapshot at least 30 days before the
     * player's latest one — null when the history doesn't reach that far back.
     *
     * @param  Collection<int, PlayerMarket>  $marketHistory  oldest first
     * @return array{multiple: float, value: int, date: string}|null
     */
    public function valueTrend(int $currentValue, Collection $marketHistory): ?array
    {
        $latest = $marketHistory->last();

        if (!$latest instanceof PlayerMarket) {
            return null;
        }

        $referenceDate = $latest->date->subDays(self::VALUE_TREND_DAYS);

        $past = $marketHistory
            ->filter(fn (PlayerMarket $snapshot): bool => $snapshot->date->lessThanOrEqualTo($referenceDate))
            ->last();

        if (!$past instanceof PlayerMarket || $past->value <= 0) {
            return null;
        }

        return [
            'multiple' => round($currentValue / $past->value, 2),
            'value' => $past->value,
            'date' => $past->date->toDateString(),
        ];
    }

    /**
     * Season points per million of current value, ranked (1 = best) among the
     * season's league players with points and a value. No rank without points.
     *
     * @return array{value: float, rank: int|null, ranked: int}|null
     */
    public function pointsPerMillion(Player $player, Season $season): ?array
    {
        if ($player->market_value <= 0) {
            return null;
        }

        $pointsPerMillion = $player->points / ($player->market_value / 1_000_000);

        $rankedPlayers = PlayerSeason::query()
            ->join('players', 'players.id', '=', 'player_seasons.player_id')
            ->where('player_seasons.season_id', $season->id)
            ->whereNotNull('players.fantasy_id')
            ->where('players.status', '!=', PlayerStatus::OutOfLeague)
            ->where('player_seasons.points', '>', 0)
            ->where('player_seasons.market_value', '>', 0);

        $rank = null;

        if ($player->points > 0) {
            // Cross-multiplied (both values are positive) so the comparison
            // stays in exact integers: theirs/their_value > mine/my_value.
            $rank = 1 + (clone $rankedPlayers)
                ->whereRaw('player_seasons.points * ? > ? * player_seasons.market_value', [$player->market_value, $player->points])
                ->count();
        }

        return [
            'value' => round($pointsPerMillion, 2),
            'rank' => $rank,
            'ranked' => $rankedPlayers->count(),
        ];
    }

    /**
     * Current value minus what the current owner paid in their latest signing
     * or buyout of this player — null for a free agent or an owner with no
     * recorded purchase (already held when they joined the league).
     *
     * @param  Collection<int, Activity>  $ownershipActivity  the player's season activity, oldest first
     * @return array{amount: int, paid: int, type: string, occurred_at: string}|null
     */
    public function capitalGain(int $currentValue, ?ManagerPlayer $owner, Collection $ownershipActivity): ?array
    {
        if (!$owner instanceof ManagerPlayer) {
            return null;
        }

        $purchase = $ownershipActivity
            ->filter(fn (Activity $activity): bool => in_array($activity->type, [SeasonActivityType::Signing, SeasonActivityType::Buyout], true)
                && $activity->source_season_manager_id === $owner->season_manager_id
                && $activity->amount !== null)
            ->sortBy('occurred_at')
            ->last();

        if (!$purchase instanceof Activity) {
            return null;
        }

        return [
            'amount' => $currentValue - (int) $purchase->amount,
            'paid' => (int) $purchase->amount,
            'type' => $purchase->type->value,
            'occurred_at' => $purchase->occurred_at->toIso8601String(),
        ];
    }
}
