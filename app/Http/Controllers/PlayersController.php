<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Http\Filters\PlayerFilter;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonTeamPlayer;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

class PlayersController extends Controller
{
    public function index(PlayerFilter $filter): Response
    {
        $season = Season::current();

        $positions = $filter->getPositions();
        $teams = $filter->getTeams();
        $statuses = $filter->getStatuses();
        $search = $filter->getSearch();
        $sort = $filter->getSort();
        $direction = $filter->getDirection();

        $players = Player::query()
            ->with('team')
            ->when($positions !== [], fn ($query) => $query->whereIn('position', $positions))
            ->when($teams !== [], fn ($query) => $query->whereIn('team_id', $teams))
            ->when($statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($search !== null, fn ($query) => $query->where('nickname', 'like', '%'.$search.'%'))
            ->orderBy($sort->column(), $direction->value)
            ->paginate(30)
            ->withQueryString();

        $this->attachOwnership($players, $season->id);

        $realTeams = Team::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('players/index', [
            'players' => $players,
            'teams' => $realTeams,
            'filters' => [
                'position' => array_map(fn (PlayerPosition $position): string => $position->value, $positions),
                'team' => $teams,
                'status' => array_map(fn (PlayerStatus $status): string => $status->value, $statuses),
                'search' => $search,
                'sort' => $sort->value,
                'direction' => $direction->value,
            ],
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
                'name' => $seasonTeam->name,
                'logo' => $seasonTeam->logo,
            ];
        });
    }
}
