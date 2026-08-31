<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesApiNextFixtures;
use App\Http\Controllers\Concerns\AttachesApiRecentScores;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Http\Controllers\Controller;
use App\Http\Filters\PlayerFilter;
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
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PlayersController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesApiNextFixtures;
    use AttachesApiRecentScores;
    use AttachesCurrentPlayerSeason;
    use AttachesOwnerManager;

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

    public function index(PlayerFilter $filter): AnonymousResourceCollection
    {
        $season = Season::current();

        $positions = $filter->getPositions();
        $teams = $filter->getTeams();
        $seasonManagers = $filter->getSeasonManagers();
        $statuses = $filter->getStatuses();
        $search = $filter->getSearch();
        $sort = $filter->getSort();
        $direction = $filter->getDirection();

        $players = Player::query()
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
            ->when($seasonManagers !== [], fn ($query) => $query->whereHas(
                'seasonManagerPlayers',
                fn ($query) => $query->whereIn('season_manager_id', $seasonManagers),
            ))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($search !== null, fn ($query) => $query->whereRaw(
                $this->foldedNicknameSql().' LIKE ?',
                ['%'.Str::lower(Str::ascii($search)).'%'],
            ))
            ->orderBy('player_seasons.'.$sort->column(), $direction->value)
            ->paginate(15);

        $this->attachOwnerManager($players->getCollection(), $season->id);
        $this->attachCurrentSeason($players->getCollection(), $season->id);
        $this->attachApiRecentScores($players->getCollection(), $season);
        $this->attachApiNextFixtures($players->getCollection(), $season);

        return PlayerResource::collection($players);
    }

    public function show(Player $player): PlayerDetailResource
    {
        abort_if($player->fantasy_id === null, 404);

        $player->load('team');
        $season = Season::current();
        $players = new Collection([$player]);

        $this->attachOwnerManager($players, $season->id);
        $this->attachCurrentSeason($players, $season->id);
        $this->attachApiNextFixtures($players, $season);
        $this->attachMarketListing($player);
        $this->attachMarketHistory($player);
        $this->attachScores($player, $season);
        $this->attachOwnershipActivity($player, $season);

        return new PlayerDetailResource($player);
    }

    private function attachMarketListing(Player $player): void
    {
        $listing = MarketPlayer::query()->where('player_id', $player->id)->first();

        $player->api_market_listing = $listing === null ? null : [
            'sale_price' => $listing->sale_price,
            'value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
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

                return [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'opponent' => (new TeamResource($isHome ? $fixture->guestTeam : $fixture->localTeam))->resolve(),
                    'is_home' => $isHome,
                    'points' => $lineup->fantasy_points,
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
