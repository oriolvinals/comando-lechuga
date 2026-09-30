<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClauseState;
use App\Enums\PayerLevel;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\PlayerSeason;
use App\Models\Season;
use Carbon\CarbonImmutable;

/**
 * Every squad player's buyout clause with its state, opportunity and which
 * rivals can pay it. PRIVATE: web /radar only.
 *
 * @phpstan-type RadarClauseShape array{player: array{id: int, nickname: string, position: string, team_short_name: string, points: int, average_points: float, status: string, market_value: int, market_value_difference: int, market_trend: string|null}, owner_id: int, amount: int, locked_until: string, shielded_until: string|null, state: string, opportunity: int, payers: list<array{manager_id: int, level: string}>}
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

            $payers = [];

            foreach ($balances as $managerId => $balance) {
                if ($managerId === $entry->season_manager_id || $managerId === $connectedManagerId) {
                    continue;
                }

                $payers[] = ['manager_id' => $managerId, 'level' => self::payerLevel($balance, $entry->buyout_clause)->value];
            }

            $rows[] = [
                'player' => [
                    'id' => $entry->player->id,
                    'nickname' => $entry->player->nickname,
                    'position' => $playerSeason->position->value,
                    'team_short_name' => $entry->player->team->short_name,
                    'points' => $playerSeason->points,
                    'average_points' => (float) $playerSeason->average_points,
                    'status' => $entry->player->status->value,
                    'market_value' => $playerSeason->market_value,
                    'market_value_difference' => $playerSeason->market_value_difference,
                    'market_trend' => $playerSeason->market_trend?->value,
                ],
                'owner_id' => $entry->season_manager_id,
                'amount' => $entry->buyout_clause,
                'locked_until' => $entry->buyout_clause_locked_until->toIso8601String(),
                'shielded_until' => $entry->shielded_until?->toIso8601String(),
                'state' => self::state($entry, isset($listed[$entry->player_id]), $now)->value,
                'opportunity' => $this->opportunity($entry->buyout_clause, $playerSeason->market_value, (float) $playerSeason->average_points),
                'payers' => $payers,
            ];
        }

        return $rows;
    }
}
