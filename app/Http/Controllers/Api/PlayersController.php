<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\ApiPlayerSort;
use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Enums\SortDirection;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\ValidatesApiQuery;
use App\Http\Controllers\Controller;
use App\Http\Filters\ApiPlayerFilter;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\PlayerDetailResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\TeamResource;
use App\Models\Activity;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Services\ApiPlayerShapes;
use App\Services\DaznEstimatePresenter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlayersController extends Controller
{
    use AttachesActivityValueDifference;
    use ValidatesApiQuery;

    private const array OWNERSHIP_ACTIVITY_TYPES = [
        SeasonActivityType::Signing,
        SeasonActivityType::Sale,
        SeasonActivityType::Buyout,
    ];

    /**
     * Diacritics found in LaLiga squads — see PlayersController's own copy for
     * the full rationale; kept identical here so search behaves the same way.
     */
    private const array ACCENT_FOLD = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n',
        'ç' => 'c',
    ];

    /** Positions the API can filter by. Coaches are never in the API. */
    private const array FILTERABLE_POSITIONS = ['goalkeeper', 'defender', 'midfield', 'striker'];

    /** Statuses the list can filter by. Out-of-league players are never listed. */
    private const array FILTERABLE_STATUSES = ['ok', 'injured', 'doubtful', 'suspended'];

    /**
     * Sort rank of `player_seasons.market_trend`: one `WHEN ? THEN ?` per
     * MarketTrend case (12), filled by trendStrengthBindings(). A missing
     * trend counts as 0.
     */
    private const string TREND_STRENGTH_SQL = 'CASE player_seasons.market_trend'
        .' WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ?'
        .' WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ? WHEN ? THEN ?'
        .' ELSE 0 END';

    /** Season points per million of current value; 0 without a value. */
    private const string POINTS_PER_MILLION_SQL = 'CASE WHEN player_seasons.market_value > 0'
        .' THEN player_seasons.points * 1.0 / player_seasons.market_value ELSE 0 END';

    public function __construct(private readonly ApiPlayerShapes $playerShapes) {}

    public function index(Request $request, ApiPlayerFilter $filter): AnonymousResourceCollection
    {
        $this->validateApiQuery($request, [
            'position' => ['sometimes', 'nullable', $this->commaSeparatedIn(self::FILTERABLE_POSITIONS)],
            'team' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'manager' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'status' => ['sometimes', 'nullable', $this->commaSeparatedIn(self::FILTERABLE_STATUSES)],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'free' => ['sometimes', 'nullable', Rule::in(['1', '0', 'true', 'false'])],
            'min_value' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'max_value' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'min_start_probability' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'sort' => ['sometimes', 'nullable', Rule::enum(ApiPlayerSort::class)],
            'direction' => ['sometimes', 'nullable', Rule::enum(SortDirection::class)],
        ]);

        $season = Season::current();

        $positions = $filter->getPositions();
        $teams = $filter->getTeams();
        $managers = $filter->getManagers();
        $statuses = $filter->getStatuses();
        $search = $filter->getSearch();
        $free = $filter->isFree();
        $minValue = $filter->getMinValue();
        $maxValue = $filter->getMaxValue();
        $minStartProbability = $filter->getMinStartProbability();
        $ownedThisSeason = fn ($query) => $query->whereHas(
            'seasonManager',
            fn ($query) => $query->where('season_id', $season->id),
        );

        $query = Player::query()
            ->select('players.*')
            ->join('player_seasons', function ($join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'players.id')
                    ->where('player_seasons.season_id', $season->id);
            })
            ->with('team')
            ->whereNotNull('fantasy_id')
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->when($positions !== [], fn ($query) => $query->whereIn('player_seasons.position', $positions))
            ->when($teams !== [], fn ($query) => $query->whereIn('team_id', $teams))
            ->when($managers !== [], fn ($query) => $query->whereHas(
                'seasonManagerPlayers',
                fn ($query) => $query->whereIn('season_manager_id', $managers),
            ))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($free === true, fn ($query) => $query->whereDoesntHave('seasonManagerPlayers', $ownedThisSeason))
            ->when($free === false, fn ($query) => $query->whereHas('seasonManagerPlayers', $ownedThisSeason))
            ->when($minValue !== null, fn ($query) => $query->where('player_seasons.market_value', '>=', $minValue))
            ->when($maxValue !== null, fn ($query) => $query->where('player_seasons.market_value', '<=', $maxValue))
            ->when($minStartProbability !== null, fn ($query) => $this->whereNextStartProbabilityAtLeast($query, $season, (int) $minStartProbability))
            ->when($search !== null, fn ($query) => $query->whereRaw(
                $this->foldedNicknameSql().' LIKE ?',
                ['%'.Str::lower(Str::ascii((string) $search)).'%'],
            ));

        $this->orderPlayers($query, $filter->getSort(), $filter->getDirection());

        $players = $query->paginate(15)->withQueryString();

        $this->playerShapes->attach($players->getCollection(), $season);

        return PlayerResource::collection($players);
    }

    public function show(Player $player): PlayerDetailResource
    {
        abort_if($player->fantasy_id === null, 404);

        $player->load('team');
        $season = Season::current();

        $this->playerShapes->attach(new Collection([$player]), $season);
        $this->attachMarketListing($player);
        $this->attachMarketHistory($player);
        $this->attachScores($player, $season);
        $this->attachOwnershipActivity($player, $season);

        return new PlayerDetailResource($player);
    }

    /**
     * @param  Builder<Player>  $query
     */
    private function orderPlayers(Builder $query, ApiPlayerSort $sort, SortDirection $direction): void
    {
        $ordered = match ($sort) {
            ApiPlayerSort::Points => $query->orderBy('player_seasons.points', $direction->value),
            ApiPlayerSort::Value => $query->orderBy('player_seasons.market_value', $direction->value),
            ApiPlayerSort::Difference => $query->orderBy('player_seasons.market_value_difference', $direction->value),
            ApiPlayerSort::Trend => $query
                ->orderByRaw(self::TREND_STRENGTH_SQL.' '.$direction->value, $this->trendStrengthBindings())
                ->orderBy('player_seasons.market_value_difference', $direction->value),
            ApiPlayerSort::PointsPerMillion => $query->orderByRaw(self::POINTS_PER_MILLION_SQL.' '.$direction->value),
        };

        $ordered->orderBy('players.id');
    }

    /**
     * @return list<int|string>
     */
    private function trendStrengthBindings(): array
    {
        $bindings = [];

        foreach (MarketTrend::cases() as $trend) {
            $bindings[] = $trend->value;
            $bindings[] = $trend->strength();
        }

        return $bindings;
    }

    /**
     * Keeps players whose FútbolFantasy start probability for their team's
     * next match is at least `$minimum`. The next match is the first scheduled
     * one still to come, the same one `next_start` describes. A confirmed
     * lineup is not considered here; `next_start.confirmed_starter` says so.
     *
     * @param  Builder<Player>  $query
     */
    private function whereNextStartProbabilityAtLeast(Builder $query, Season $season, int $minimum): void
    {
        $query->whereExists(fn ($exists) => $exists
            ->selectRaw('1')
            ->from('fixture_lineup_probabilities')
            ->whereColumn('fixture_lineup_probabilities.player_id', 'players.id')
            ->where('fixture_lineup_probabilities.probability', '>=', $minimum)
            ->where('fixture_lineup_probabilities.fixture_id', fn ($next) => $next
                ->select('fixtures.id')
                ->from('fixtures')
                ->where('fixtures.season_id', $season->id)
                ->where('fixtures.state', FixtureState::Scheduled->value)
                ->where('fixtures.date', '>', now())
                ->where(fn ($teams) => $teams
                    ->whereColumn('fixtures.team_local_id', 'players.team_id')
                    ->orWhereColumn('fixtures.team_guest_id', 'players.team_id'))
                ->orderBy('fixtures.date')
                ->limit(1)));
    }

    private function attachMarketListing(Player $player): void
    {
        $listing = MarketPlayer::query()->where('player_id', $player->id)->first();

        $player->api_market_listing = $listing === null ? null : [
            'sale_price' => $listing->sale_price,
            'market_value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
            'seller' => MarketPlayer::SELLER_LEAGUE,
        ];
    }

    private function attachMarketHistory(Player $player): void
    {
        $player->api_market_history = PlayerMarket::query()
            ->where('player_id', $player->id)
            ->orderBy('date')
            ->get(['date', 'value'])
            ->map(fn (PlayerMarket $market): array => [
                'date' => $market->date->toDateString(),
                'value' => $market->value,
            ])
            ->all();
    }

    private function attachScores(Player $player, Season $season): void
    {
        $lineups = $player->fixtureLineups()
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with(['fixture.localTeam', 'fixture.guestTeam'])
            ->get()
            ->sortBy(fn (FixtureLineup $lineup) => $lineup->fixture->week_number)
            ->values();

        $lineupManagersByFixture = ManagerLineupPlayer::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', $lineups->pluck('fixture_id')->filter())
            ->whereHas('lineup.seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('fixture_id');

        $player->api_scores = $lineups
            ->map(function (FixtureLineup $lineup) use ($lineupManagersByFixture): array {
                $fixture = $lineup->fixture;
                $isHome = $fixture->team_local_id === $lineup->team_id;
                $seasonManager = $lineupManagersByFixture->get($fixture->id)?->lineup?->seasonManager;
                $dazn = DaznEstimatePresenter::present($lineup, $fixture);

                return [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'fixture_state' => $fixture->state->value,
                    'opponent' => (new TeamResource($isHome ? $fixture->guestTeam : $fixture->localTeam))->resolve(),
                    'is_home' => $isHome,
                    'points' => $lineup->fantasy_points,
                    'minutes' => $this->statPair($lineup->fantasy_stats, 'mins_played', 0),
                    'marca_points' => $this->statPair($lineup->fantasy_stats, 'marca_points', 1),
                    'dazn_estimate' => $dazn['dazn_estimate'],
                    'dazn_estimate_version' => $dazn['dazn_estimate_version'],
                    'starter' => $lineup->starter,
                    'subbed_in' => $lineup->subbed_in,
                    'subbed_out' => $lineup->subbed_out,
                    'sub_minute' => $lineup->sub_minute,
                    'stats' => $lineup->fantasy_stats,
                    'lineup_manager' => $seasonManager === null ? null : [
                        'id' => $seasonManager->id,
                        'name' => $seasonManager->name,
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One number of a `[value, fantasy points]` stats pair. `$index` 0 is the
     * raw value (e.g. minutes played) and 1 the points it earned (e.g. the
     * DAZN rating's points). Null when the stat is missing.
     *
     * @param  array<string, mixed>|null  $stats
     */
    private function statPair(?array $stats, string $key, int $index): ?int
    {
        $pair = $stats[$key] ?? null;

        if (!is_array($pair) || !isset($pair[$index]) || !is_numeric($pair[$index])) {
            return null;
        }

        return (int) $pair[$index];
    }

    private function attachOwnershipActivity(Player $player, Season $season): void
    {
        $activity = Activity::query()
            ->where('season_id', $season->id)
            ->where('player_id', $player->id)
            ->whereIn('type', self::OWNERSHIP_ACTIVITY_TYPES)
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderBy('occurred_at')
            ->get();

        $this->attachValueDifferences($activity);

        $player->api_ownership_activity = ActivityResource::collection($activity)->resolve();
    }

    /** @return literal-string */
    private function foldedNicknameSql(): string
    {
        $expression = 'LOWER(nickname)';

        foreach (self::ACCENT_FOLD as $accented => $plain) {
            $expression = "REPLACE({$expression}, '{$accented}', '{$plain}')";
        }

        return $expression;
    }
}
