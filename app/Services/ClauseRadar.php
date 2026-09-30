<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClauseState;
use App\Enums\PayerLevel;
use App\Enums\PlayerPosition;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use Carbon\CarbonImmutable;

/**
 * Every squad player's buyout clause with its state, opportunity and which
 * rivals can pay it, and the same payers for every market listing.
 * PRIVATE: web /radar only.
 *
 * @phpstan-type RadarPlayerShape array{id: int, nickname: string, image: string, position: string, team_short_name: string, points: int, average_points: float, status: string, market_value: int, market_value_difference: int, market_trend: string|null}
 * @phpstan-type RadarPayersShape list<array{manager_id: int, level: string}>
 * @phpstan-type RadarClauseShape array{player: RadarPlayerShape, owner_id: int, amount: int, locked_until: string, shielded_until: string|null, state: string, opportunity: int, payers: RadarPayersShape}
 * @phpstan-type RadarMarketShape array{player: RadarPlayerShape, listing_id: int, seller_id: int|null, price: int, value: int, bids: int, expires_at: string, payers: RadarPayersShape}
 */
final class ClauseRadar
{
    public function __construct(private readonly ClauseOpportunityParameters $parameters) {}

    public static function state(ManagerPlayer $entry, bool $listed, CarbonImmutable $now): ClauseState
    {
        if ($listed) {
            return ClauseState::Listed;
        }

        if ($entry->shielded && $entry->shielded_until !== null && $entry->shielded_until->greaterThan($now)) {
            return ClauseState::Shielded;
        }

        return $entry->buyout_clause_locked_until->greaterThan($now) ? ClauseState::Locked : ClauseState::Open;
    }

    public static function payerLevel(ManagerBalance $balance, int $clause): PayerLevel
    {
        if ($balance->low() >= $clause) {
            return PayerLevel::Sure;
        }

        return $balance->high() >= $clause ? PayerLevel::Maybe : PayerLevel::No;
    }

    public function opportunity(int $clause, int $value, float $average): int
    {
        $ratio = min($this->parameters->valueRatioCap, $value / max(1, $clause)) / $this->parameters->valueRatioCap;
        $form = $this->parameters->formFloor + $this->parameters->formWeight * min(1.0, $average / $this->parameters->averageTarget);

        return (int) round(100 * $ratio * $form);
    }

    /**
     * @param  array<int, ManagerBalance>  $balances
     * @return list<RadarClauseShape>
     */
    public function forSeason(Season $season, array $balances, ?int $connectedManagerId, CarbonImmutable $now): array
    {
        $listed = array_flip(MarketPlayer::query()->pluck('player_id')->all());
        $rows = [];

        $entries = ManagerPlayer::query()
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with(['player.team', 'player.seasons' => fn ($query) => $query->where('season_id', $season->id)])
            ->get();

        foreach ($entries as $entry) {
            $playerSeason = $entry->player->seasons->first();

            if (!$playerSeason instanceof PlayerSeason) {
                continue;
            }

            $rows[] = [
                'player' => self::player($entry->player, $playerSeason),
                'owner_id' => $entry->season_manager_id,
                'amount' => $entry->buyout_clause,
                'locked_until' => $entry->buyout_clause_locked_until->toIso8601String(),
                'shielded_until' => $entry->shielded_until?->toIso8601String(),
                'state' => self::state($entry, isset($listed[$entry->player_id]), $now)->value,
                'opportunity' => $this->opportunity($entry->buyout_clause, $playerSeason->market_value, (float) $playerSeason->average_points),
                'payers' => self::payers($balances, $entry->buyout_clause, [$entry->season_manager_id, $connectedManagerId]),
            ];
        }

        return $rows;
    }

    /**
     * The live market listings (coaches left out, as on the home market),
     * soonest to expire first. The seller is the manager who owns the player
     * this season, or null for a free listing by the league.
     *
     * @param  array<int, ManagerBalance>  $balances
     * @return list<RadarMarketShape>
     */
    public function marketRows(Season $season, array $balances, ?int $connectedManagerId, CarbonImmutable $now): array
    {
        $listings = MarketPlayer::query()
            ->where('expires_at', '>', $now)
            ->with(['player.team', 'player.seasons' => fn ($query) => $query->where('season_id', $season->id)])
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();

        /** @var array<int, int> $sellers */
        $sellers = ManagerPlayer::query()
            ->whereIn('player_id', $listings->pluck('player_id'))
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->pluck('season_manager_id', 'player_id')
            ->all();

        $rows = [];

        foreach ($listings as $listing) {
            $playerSeason = $listing->player->seasons->first();

            if (!$playerSeason instanceof PlayerSeason || $playerSeason->position === PlayerPosition::Coach) {
                continue;
            }

            $sellerId = $sellers[$listing->player_id] ?? null;

            $rows[] = [
                'player' => self::player($listing->player, $playerSeason),
                'listing_id' => $listing->id,
                'seller_id' => $sellerId,
                'price' => $listing->sale_price,
                'value' => $listing->value,
                'bids' => $listing->bids,
                'expires_at' => $listing->expires_at->toIso8601String(),
                'payers' => self::payers($balances, $listing->sale_price, [$sellerId, $connectedManagerId]),
            ];
        }

        return $rows;
    }

    /** @return RadarPlayerShape */
    private static function player(Player $player, PlayerSeason $playerSeason): array
    {
        return [
            'id' => $player->id,
            'nickname' => $player->nickname,
            'image' => $player->image ? asset('storage/'.$player->image) : '',
            'position' => $playerSeason->position->value,
            'team_short_name' => $player->team->short_name,
            'points' => $playerSeason->points,
            'average_points' => (float) $playerSeason->average_points,
            'status' => $player->status->value,
            'market_value' => $playerSeason->market_value,
            'market_value_difference' => $playerSeason->market_value_difference,
            'market_trend' => $playerSeason->market_trend?->value,
        ];
    }

    /**
     * Each manager's payer level for an amount, in the balances' order, without the excluded managers.
     *
     * @param  array<int, ManagerBalance>  $balances
     * @param  list<int|null>  $excludedManagerIds
     * @return RadarPayersShape
     */
    private static function payers(array $balances, int $amount, array $excludedManagerIds): array
    {
        $payers = [];

        foreach ($balances as $managerId => $balance) {
            if (in_array($managerId, $excludedManagerIds, true)) {
                continue;
            }

            $payers[] = ['manager_id' => $managerId, 'level' => self::payerLevel($balance, $amount)->value];
        }

        return $payers;
    }
}
