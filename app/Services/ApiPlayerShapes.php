<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesApiNextFixtures;
use App\Http\Controllers\Concerns\AttachesApiRecentScores;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Http\Resources\TeamResource;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Attaches, in batch, everything PlayerResource / PlayerDetailResource show
 * beyond the Player row, for players of one season:
 * - owner and season figures;
 * - recent scores;
 * - next fixtures with rival difficulty;
 * - `next_start` (FútbolFantasy probability or the confirmed lineup);
 * - the value metrics.
 *
 * Every API surface that shows a player goes through this service, so the
 * shape is identical in /players, /players/{id}, /market and manager
 * rosters.
 *
 * @phpstan-import-type PlayerNextStart from StartProbabilities
 */
class ApiPlayerShapes
{
    use AttachesApiNextFixtures;
    use AttachesApiRecentScores;
    use AttachesCurrentPlayerSeason;
    use AttachesOwnerManager;

    public function __construct(
        private readonly StartProbabilities $startProbabilities,
        private readonly PlayerMarketMetrics $marketMetrics,
    ) {}

    /**
     * @param  Collection<int, Player>  $players  with `team` loaded
     */
    public function attach(Collection $players, Season $season): void
    {
        $this->attachOwnerManager($players, $season->id);
        $this->attachCurrentSeason($players, $season->id);
        $this->attachApiRecentScores($players, $season);
        $this->attachApiNextFixtures($players, $season);
        $this->attachNextStarts($players, $season);
        $this->attachValueMetrics($players, $season);
    }

    /**
     * The public shape of a `next_start`: StartProbabilities' block without
     * the Team model and the short name. `source` is the confirmed lineup's
     * source once there is one, else FútbolFantasy (always to be credited).
     *
     * @param  PlayerNextStart  $start
     * @return array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, source: string, is_stale: bool, fetched_at: string|null, source_url: string}
     */
    public static function presentNextStart(array $start): array
    {
        return [
            'fixture_id' => $start['fixture_id'],
            'week_number' => $start['week_number'],
            'date' => $start['date'],
            'opponent' => (new TeamResource($start['opponent']))->resolve(),
            'is_home' => $start['is_home'],
            'probability' => $start['probability'],
            'predicted_starter' => $start['predicted_starter'],
            'confirmed_starter' => $start['confirmed_starter'],
            'source' => $start['confirmed_source'] ?? 'futbolfantasy',
            'is_stale' => $start['is_stale'],
            'fetched_at' => $start['fetched_at'],
            'source_url' => $start['source_url'],
        ];
    }

    /**
     * Null when there is no data for the player's next match. That includes
     * a postponed match, a player FútbolFantasy doesn't list and an
     * out-of-league player. Null never means 0 %.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachNextStarts(Collection $players, Season $season): void
    {
        $nextStarts = $this->startProbabilities->forPlayersNextFixture($players, $season);

        $players->each(function (Player $player) use ($nextStarts): void {
            $nextStart = $nextStarts[$player->id] ?? null;

            $player->api_next_start = $nextStart === null ? null : self::presentNextStart($nextStart);
        });
    }

    /**
     * The 30-day value multiple, season points per million (with its rank)
     * and the owner's paper gain, batched. All three are null for a player
     * without season figures.
     *
     * @param  Collection<int, Player>  $players
     */
    private function attachValueMetrics(Collection $players, Season $season): void
    {
        $playerIds = $players->pluck('id')->all();

        $historyByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $playerIds)
            ->orderBy('date')
            ->get()
            ->groupBy('player_id');

        $owners = ManagerPlayer::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get()
            ->keyBy('player_id');

        $purchasesByPlayer = Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $playerIds)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('player_id');

        $pointsPerMillionByPlayer = $this->marketMetrics->pointsPerMillionForPlayers(
            $players->filter(fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague),
            $season,
        );

        $players->each(function (Player $player) use ($historyByPlayer, $owners, $purchasesByPlayer, $pointsPerMillionByPlayer): void {
            $marketValue = $player->getAttribute('market_value');

            if (!is_int($marketValue)) {
                $player->api_value_trend_30d = null;
                $player->api_points_per_million = null;
                $player->api_owner_gain = null;

                return;
            }

            /** @var Collection<int, PlayerMarket> $history */
            $history = $historyByPlayer->get($player->id) ?? collect();

            /** @var Collection<int, Activity> $purchases */
            $purchases = $purchasesByPlayer->get($player->id) ?? collect();

            $player->api_value_trend_30d = $this->marketMetrics->valueTrend($marketValue, $history);
            $player->api_points_per_million = $pointsPerMillionByPlayer[$player->id] ?? null;
            $player->api_owner_gain = $this->marketMetrics->capitalGain($marketValue, $owners->get($player->id), $purchases);
        });
    }
}
