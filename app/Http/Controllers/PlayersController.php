<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesNextFixtures;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Filters\PlayerFilter;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PlayersController extends Controller
{
    use AttachesCurrentPlayerSeason;
    use AttachesNextFixtures;
    use AttachesRecentScores;

    /**
     * Diacritics found in LaLiga squads (Spanish, Portuguese, French, German
     * names) — folded away so a search for "Valentin" also matches "Valentín".
     * SQLite has no built-in unaccent, so the column side is folded with
     * nested REPLACE() while the search term is folded in PHP via Str::ascii().
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

    /** @return literal-string */
    private function foldedNicknameSql(): string
    {
        $expression = 'LOWER(nickname)';

        foreach (self::ACCENT_FOLD as $accented => $plain) {
            $expression = "REPLACE({$expression}, '{$accented}', '{$plain}')";
        }

        return $expression;
    }

    public function index(PlayerFilter $filter): Response
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
            ->paginate(15)
            ->withQueryString();

        $this->attachOwnership($players, $season->id);
        $this->attachCurrentSeason($players->getCollection(), $season->id);
        $this->attachRecentScores($players->getCollection(), $season);
        $this->attachNextFixtures($players->getCollection(), $season);

        $realTeams = Team::query()
            ->orderBy('main_name')
            ->get(['id', 'main_name']);

        $seasonManagerOptions = SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('players/index', [
            'players' => $players,
            'teams' => $realTeams,
            'seasonManagers' => $seasonManagerOptions,
            'filters' => [
                'position' => array_map(fn (PlayerPosition $position): string => $position->value, $positions),
                'team' => $teams,
                'seasonManager' => $seasonManagers,
                'status' => array_map(fn (PlayerStatus $status): string => $status->value, $statuses),
                'search' => $search,
                'sort' => $sort->value,
                'direction' => $direction->value,
            ],
        ]);
    }

    private const array OWNERSHIP_ACTIVITY_TYPES = [
        SeasonActivityType::Signing,
        SeasonActivityType::Sale,
        SeasonActivityType::Buyout,
    ];

    public function show(Player $player): Response
    {
        abort_if($player->fantasy_id === null, 404);

        $player->load('team');
        $season = Season::current();

        $this->attachCurrentSeason(new Collection([$player]), $season->id);
        $this->attachNextFixtures(new Collection([$player]), $season);

        $owner = ManagerPlayer::query()
            ->where('player_id', $player->id)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with('seasonManager')
            ->first();

        $marketListing = MarketPlayer::query()
            ->where('player_id', $player->id)
            ->first();

        $marketHistory = PlayerMarket::query()
            ->where('player_id', $player->id)
            ->orderBy('date')
            ->get(['date', 'value']);

        $scores = $player->fixtureLineups()
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with(['fixture.localTeam', 'fixture.guestTeam', 'team'])
            ->get()
            ->sortBy(fn (FixtureLineup $lineup) => $lineup->fixture->week_number)
            ->values()
            ->map(fn (FixtureLineup $lineup): array => [
                'id' => $lineup->id,
                'team_id' => $lineup->team_id,
                'team' => $lineup->team,
                'points' => $lineup->fantasy_points,
                'stats' => $lineup->fantasy_stats,
                'fixture' => $lineup->fixture,
                'lineup_manager' => null,
            ]);

        // Which manager fielded this player in their lineup each jornada — distinct
        // from ownership, since an owner can bench a player they still own.
        $lineupManagersByFixture = ManagerLineupPlayer::query()
            ->where('player_id', $player->id)
            ->whereIn('fixture_id', $scores->pluck('fixture.id')->filter())
            ->whereHas('lineup.seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('fixture_id');

        $scores = $scores->map(function (array $score) use ($lineupManagersByFixture): array {
            $score['lineup_manager'] = $lineupManagersByFixture->get($score['fixture']->id)?->lineup?->seasonManager;

            return $score;
        });

        $ownershipActivity = Activity::query()
            ->where('season_id', $season->id)
            ->where('player_id', $player->id)
            ->whereIn('type', self::OWNERSHIP_ACTIVITY_TYPES)
            ->with(['sourceSeasonManager', 'targetSeasonManager'])
            ->orderBy('occurred_at')
            ->get();

        // A player already on a manager's squad when that manager joined the league
        // has no signing/buyout of their own to explain it — this is true not just for
        // the current owner, but for whichever manager a sale/buyout implies held the
        // player *before* the earliest recorded activity. Every manager's join date lets
        // the frontend fall back to it instead of crediting a manager further back than
        // they've actually existed in the league.
        $teamJoinedAt = Activity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::JoinedLeague)
            ->get(['source_season_manager_id', 'occurred_at'])
            ->mapWithKeys(fn (Activity $activity): array => [
                (string) $activity->source_season_manager_id => $activity->occurred_at,
            ]);

        // Fixtures for the player's current club up to the current week, including weeks
        // that haven't produced a FixtureLineup yet — lets the match timeline link to a
        // fixture (e.g. "aún no jugada") before any stats exist for it.
        $teamFixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', '<=', $season->current_week)
            ->where(fn ($query) => $query
                ->where('team_local_id', $player->team_id)
                ->orWhere('team_guest_id', $player->team_id))
            ->with(['localTeam', 'guestTeam'])
            ->get();

        return Inertia::render('players/show', [
            'player' => $player,
            'season' => $season,
            'owner' => $owner,
            'marketListing' => $marketListing,
            'marketHistory' => $marketHistory,
            'scores' => $scores,
            'ownershipActivity' => $ownershipActivity,
            'teamJoinedAt' => $teamJoinedAt,
            'teamFixtures' => $teamFixtures,
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, Player>  $players
     */
    private function attachOwnership(LengthAwarePaginator $players, int $seasonId): void
    {
        /** @var Collection<int, Player> $entries */
        $entries = $players->getCollection();
        $playerIds = $entries->pluck('id')->all();

        $owners = ManagerPlayer::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $seasonId))
            ->with('seasonManager')
            ->get()
            ->keyBy('player_id');

        $entries->each(function (Player $player) use ($owners): void {
            $seasonManager = $owners->get($player->id)?->seasonManager;

            $player->owner_manager = $seasonManager === null ? null : [
                'id' => $seasonManager->id,
                'name' => $seasonManager->name,
                'logo' => $seasonManager->logo,
                'primary_color' => $seasonManager->primary_color,
            ];
        });
    }
}
