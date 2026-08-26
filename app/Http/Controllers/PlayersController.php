<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Filters\PlayerFilter;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineupPlayer;
use App\Models\SeasonTeamPlayer;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class PlayersController extends Controller
{
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
        $seasonTeams = $filter->getSeasonTeams();
        $statuses = $filter->getStatuses();
        $search = $filter->getSearch();
        $sort = $filter->getSort();
        $direction = $filter->getDirection();

        $players = Player::query()
            ->with('team')
            ->when($positions !== [], fn ($query) => $query->whereIn('position', $positions))
            ->when($teams !== [], fn ($query) => $query->whereIn('team_id', $teams))
            ->when($seasonTeams !== [], fn ($query) => $query->whereHas(
                'seasonTeamPlayers',
                fn ($query) => $query->whereIn('season_team_id', $seasonTeams),
            ))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($search !== null, fn ($query) => $query->whereRaw(
                $this->foldedNicknameSql().' LIKE ?',
                ['%'.Str::lower(Str::ascii($search)).'%'],
            ))
            ->orderBy($sort->column(), $direction->value)
            ->paginate(15)
            ->withQueryString();

        $this->attachOwnership($players, $season->id);
        $this->attachRecentScores($players->getCollection(), $season);

        $realTeams = Team::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        $seasonTeamOptions = SeasonTeam::query()
            ->where('season_id', $season->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('players/index', [
            'players' => $players,
            'teams' => $realTeams,
            'seasonTeams' => $seasonTeamOptions,
            'filters' => [
                'position' => array_map(fn (PlayerPosition $position): string => $position->value, $positions),
                'team' => $teams,
                'seasonTeam' => $seasonTeams,
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
        $player->load('team');
        $season = Season::current();

        $owner = SeasonTeamPlayer::query()
            ->where('player_id', $player->id)
            ->whereHas('seasonTeam', fn ($query) => $query->where('season_id', $season->id))
            ->with('seasonTeam')
            ->first();

        $marketListing = MarketPlayer::query()
            ->where('player_id', $player->id)
            ->first();

        $marketHistory = PlayerMarket::query()
            ->where('player_id', $player->id)
            ->orderBy('date')
            ->get(['date', 'value']);

        $scores = $player->scores()
            ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id))
            ->with(['fixture.localTeam', 'fixture.guestTeam', 'team'])
            ->get()
            ->sortBy(fn ($score) => $score->fixture->week_number)
            ->values();

        // Which fantasy team fielded this player in their lineup each jornada — distinct
        // from ownership, since an owner can bench a player they still own.
        $lineupTeamsByWeek = SeasonTeamLineupPlayer::query()
            ->where('player_id', $player->id)
            ->whereHas('lineup.seasonTeam', fn ($query) => $query->where('season_id', $season->id))
            ->with('lineup.seasonTeam')
            ->get()
            ->keyBy(fn (SeasonTeamLineupPlayer $entry): int => $entry->lineup->week_number);

        $scores->each(function (PlayerScore $score) use ($lineupTeamsByWeek): void {
            $score->lineup_team = $lineupTeamsByWeek->get($score->fixture->week_number)?->lineup?->seasonTeam;
        });

        $ownershipActivity = SeasonActivity::query()
            ->where('season_id', $season->id)
            ->where('player_id', $player->id)
            ->whereIn('type', self::OWNERSHIP_ACTIVITY_TYPES)
            ->with(['sourceSeasonTeam', 'targetSeasonTeam'])
            ->orderBy('occurred_at')
            ->get();

        // A player already on a manager's squad when that manager joined the league
        // has no signing/buyout of their own to explain it — this is true not just for
        // the current owner, but for whichever team a sale/buyout implies held the
        // player *before* the earliest recorded activity. Every team's join date lets
        // the frontend fall back to it instead of crediting a team further back than
        // they've actually existed in the league.
        $teamJoinedAt = SeasonActivity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::JoinedLeague)
            ->get(['source_season_team_id', 'occurred_at'])
            ->mapWithKeys(fn (SeasonActivity $activity): array => [
                (string) $activity->source_season_team_id => $activity->occurred_at,
            ]);

        // Fixtures for the player's current club up to the current week, including weeks
        // that haven't produced a PlayerScore yet — lets the match timeline link to a
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

        $owners = SeasonTeamPlayer::query()
            ->whereIn('player_id', $playerIds)
            ->whereHas('seasonTeam', fn ($query) => $query->where('season_id', $seasonId))
            ->with('seasonTeam')
            ->get()
            ->keyBy('player_id');

        $entries->each(function (Player $player) use ($owners): void {
            $seasonTeam = $owners->get($player->id)?->seasonTeam;

            $player->owner_team = $seasonTeam === null ? null : [
                'id' => $seasonTeam->id,
                'name' => $seasonTeam->name,
                'logo' => $seasonTeam->logo,
                'primary_color' => $seasonTeam->primary_color,
            ];
        });
    }
}
