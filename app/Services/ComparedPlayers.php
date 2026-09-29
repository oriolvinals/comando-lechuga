<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesNextFixtures;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Models\Activity;
use App\Models\FixtureLineup;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Everything the comparator shows about each compared player, batched in a
 * fixed number of queries whatever the number of players. Reuses the ficha's
 * pieces rather than re-deriving them: the season figures and next fixtures
 * traits, StartProbabilities, PlayerMarketMetrics, DaznEstimatePresenter and
 * BuyoutClausePresenter.
 *
 * @phpstan-import-type PlayerNextStart from StartProbabilities
 *
 * @phpstan-type ComparedPlayerScore array{fixture_id: int, week_number: int, fixture_state: string, opponent: Team|null, is_home: bool, points: int|null, minutes: int, starter: bool, dazn_points: int|null, dazn_estimate: int|null, dazn_estimate_version: string, dazn_estimate_reasons: list<string>, dazn_estimate_source: string|null}
 * @phpstan-type ComparedPlayerShape array{id: int, name: string, image: string, position: string|null, status: string, team: Team|null, value: int, difference: int, trend: string|null, value_trend_30d: array{multiple: float, value: int, date: string}|null, market_history: list<array{0: string, 1: int}>, points: int, average_points: float, points_per_million: array{value: float, rank: int|null, ranked: int}|null, scores: list<ComparedPlayerScore>, next_fixtures: array<int, mixed>, next_start: PlayerNextStart|null, owner: array{id: int, name: string, logo: string, color: string|null}|null, clause: array{amount: int, locked_until: string, is_locked: bool, shielded: bool, shielded_until: string|null, purchase: array{amount: int, type: string, occurred_at: string}|null}|null, listing: array{sale_price: int, bids: int, expires_at: string, seller: string}|null}
 */
final class ComparedPlayers
{
    use AttachesCurrentPlayerSeason;
    use AttachesNextFixtures;
    use AttachesOwnerManager;

    /** Snapshots sent for the value chart: today and the 30 days before. */
    public const int MARKET_HISTORY_DAYS = 31;

    public function __construct(
        private readonly PlayerMarketMetrics $marketMetrics,
        private readonly StartProbabilities $startProbabilities,
    ) {}

    /**
     * @param  list<int>  $ids  validated ids, in display order
     * @return list<ComparedPlayerShape>
     */
    public function forIds(array $ids, Season $season): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, Player> $players */
        $players = Player::query()
            ->with('team')
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Player $player): int => (int) array_search($player->id, $ids, true))
            ->values();

        $this->attachCurrentSeason($players, $season->id);
        $this->attachOwnerManager($players, $season->id);
        $this->attachNextFixtures($players, $season);

        $nextStarts = $this->startProbabilities->forPlayersNextFixture($players, $season);
        $pointsPerMillion = $this->marketMetrics->pointsPerMillionForPlayers(
            $players->filter(fn (Player $player): bool => $player->status !== PlayerStatus::OutOfLeague),
            $season,
        );

        $historyByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $ids)
            ->orderBy('date')
            ->get()
            ->groupBy('player_id');

        $clauses = ManagerPlayer::query()
            ->whereIn('player_id', $ids)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get()
            ->keyBy('player_id');

        $purchasesByPlayer = Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $ids)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->whereNotNull('amount')
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('player_id');

        $listings = MarketPlayer::query()->whereIn('player_id', $ids)->get()->keyBy('player_id');

        $lineupsByPlayer = FixtureLineup::query()
            ->whereIn('player_id', $ids)
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with(['fixture.localTeam', 'fixture.guestTeam'])
            ->get()
            ->groupBy('player_id');

        return array_values($players
            ->map(function (Player $player) use ($nextStarts, $pointsPerMillion, $historyByPlayer, $clauses, $purchasesByPlayer, $listings, $lineupsByPlayer): array {
                /** @var Collection<int, PlayerMarket> $history */
                $history = $historyByPlayer->get($player->id) ?? new Collection;
                $clause = $clauses->get($player->id);
                $purchase = $clause === null ? null : ($purchasesByPlayer->get($player->id) ?? new Collection)
                    ->filter(fn (Activity $activity): bool => $activity->source_season_manager_id === $clause->season_manager_id)
                    ->last();
                $listing = $listings->get($player->id);
                $owner = $player->owner_manager;

                return [
                    'id' => $player->id,
                    'name' => $player->nickname,
                    'image' => $player->image !== '' ? asset('storage/'.$player->image) : '',
                    'position' => $player->position?->value,
                    'status' => $player->status->value,
                    'team' => $player->team,
                    'value' => $player->market_value,
                    'difference' => $player->market_value_difference,
                    'trend' => $player->market_trend?->value,
                    'value_trend_30d' => $this->marketMetrics->valueTrend($player->market_value, $history),
                    'market_history' => array_values($history
                        ->slice(-self::MARKET_HISTORY_DAYS)
                        ->map(fn (PlayerMarket $snapshot): array => [$snapshot->date->toDateString(), $snapshot->value])
                        ->all()),
                    'points' => $player->points,
                    'average_points' => (float) $player->average_points,
                    'points_per_million' => $pointsPerMillion[$player->id] ?? null,
                    'scores' => $this->scores($lineupsByPlayer->get($player->id) ?? new Collection),
                    'next_fixtures' => $player->next_fixtures,
                    'next_start' => $nextStarts[$player->id] ?? null,
                    'owner' => $owner === null ? null : [
                        'id' => $owner['id'],
                        'name' => $owner['name'],
                        'logo' => $owner['logo'],
                        'color' => $owner['primary_color'],
                    ],
                    'clause' => $clause === null ? null : [
                        ...BuyoutClausePresenter::clause($clause),
                        'purchase' => BuyoutClausePresenter::purchase($purchase),
                    ],
                    'listing' => $listing === null ? null : [
                        'sale_price' => $listing->sale_price,
                        'bids' => $listing->bids,
                        'expires_at' => $listing->expires_at->toIso8601String(),
                        'seller' => MarketPlayer::SELLER_LEAGUE,
                    ],
                ];
            })
            ->all());
    }

    /**
     * One entry per lineup row of the season, in jornada order (kickoff
     * breaks a tie), with the same DAZN visibility rule as the ficha.
     *
     * @param  SupportCollection<int, FixtureLineup>  $lineups
     * @return list<ComparedPlayerScore>
     */
    private function scores(SupportCollection $lineups): array
    {
        return array_values($lineups
            ->sortBy(fn (FixtureLineup $lineup): string => sprintf('%03d-%s', $lineup->fixture->week_number, $lineup->fixture->date->toIso8601String()))
            ->map(function (FixtureLineup $lineup): array {
                $fixture = $lineup->fixture;
                $isHome = $fixture->team_local_id === $lineup->team_id;

                return [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'fixture_state' => $fixture->state->value,
                    'opponent' => $isHome ? $fixture->guestTeam : $fixture->localTeam,
                    'is_home' => $isHome,
                    'points' => $lineup->fantasy_points,
                    'minutes' => (int) ($lineup->fantasy_stats['mins_played'][0] ?? 0),
                    'starter' => $lineup->starter,
                    ...DaznEstimatePresenter::present($lineup, $fixture),
                ];
            })
            ->all());
    }
}
