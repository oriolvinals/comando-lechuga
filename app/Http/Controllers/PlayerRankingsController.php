<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FantasyStat;
use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Filters\PlayerFilter;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use App\Services\PlayerStatRankings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Jugadores page's «Rankings» view: the season leaders of every LaLiga
 * Fantasy action, over the season or one jornada, filtered by position and
 * team, and by at most one manager — whose rankings count only the matches
 * he lined each player up for.
 * Without `stat`, an overview of every action's top 5; with it, that
 * action's full ranking with who lined each player up; with `player` too,
 * that player's value in each match of the season.
 *
 * @phpstan-import-type RankedPlayer from PlayerStatRankings
 * @phpstan-import-type LineupManager from PlayerStatRankings
 */
class PlayerRankingsController extends Controller
{
    use AttachesCurrentPlayerSeason;

    private const int OVERVIEW_TOP = 5;

    public function __invoke(Request $request, PlayerFilter $filter, PlayerStatRankings $rankings): Response
    {
        $season = Season::current();
        $stat = FantasyStat::tryFrom($request->string('stat')->toString());
        $positions = $filter->getPositions();
        $teams = $filter->getTeams();
        $managerId = $filter->getSeasonManagers()[0] ?? null;

        $players = $this->players($season, $positions, $teams, $managerId === null ? null : $rankings->linedUpPlayerIds($season, $managerId));
        $weeksPlayed = (int) Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotIn('state', [FixtureState::Scheduled, FixtureState::Postponed])
            ->max('week_number');
        $week = $request->integer('jornada');
        $week = $week >= 1 && $week <= $weeksPlayed ? $week : null;
        $totals = $rankings->totals($season, $managerId, $week);

        $breakdown = null;

        if ($stat !== null && $request->integer('player') > 0) {
            $player = $players->firstWhere('id', $request->integer('player'));

            if ($player instanceof Player) {
                $breakdown = [
                    'player_id' => $player->id,
                    'matches' => array_map(fn (array $match): array => [
                        ...$match,
                        'rival' => self::team($match['rival']),
                    ], $rankings->matches($player, $season, $stat)),
                ];
            }
        }

        $ranking = null;

        if ($stat !== null) {
            $ranked = $rankings->rank($players, $totals, $stat);
            $linedUpBy = $rankings->linedUpBy($season, array_map(fn (array $row): int => $row['player']->id, $ranked), $week);
            $ranking = $this->rows($ranked, $linedUpBy);
        }

        return Inertia::render('players/rankings', [
            'weeksPlayed' => $weeksPlayed,
            'stats' => array_map(fn (FantasyStat $case): array => [
                'key' => $case->value,
                'bad' => $case->isBad(),
            ], FantasyStat::cases()),
            'stat' => $stat?->value,
            'overview' => $stat !== null ? null : array_map(function (FantasyStat $case) use ($rankings, $players, $totals): array {
                $ranked = $rankings->rank($players, $totals, $case);

                return [
                    'stat' => $case->value,
                    'count' => count($ranked),
                    'rows' => $this->rows(array_slice($ranked, 0, self::OVERVIEW_TOP)),
                ];
            }, FantasyStat::cases()),
            'ranking' => $ranking,
            'breakdown' => $breakdown,
            'teams' => Team::query()->orderBy('main_name')->get(['id', 'main_name']),
            'seasonManagers' => SeasonManager::query()->where('season_id', $season->id)->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'position' => array_map(fn (PlayerPosition $position): string => $position->value, $positions),
                'team' => $teams,
                'seasonManager' => $managerId,
                'week' => $week,
            ],
        ]);
    }

    /**
     * The players the Jugadores list would show for these filters (in the
     * league, with a LaLiga Fantasy id) — only $linedUpIds when a manager is
     * chosen — with their team and season position.
     *
     * @param  PlayerPosition[]  $positions
     * @param  int[]  $teams
     * @param  list<int>|null  $linedUpIds
     * @return Collection<int, Player>
     */
    private function players(Season $season, array $positions, array $teams, ?array $linedUpIds): Collection
    {
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
            ->when($linedUpIds !== null, fn ($query) => $query->whereIn('players.id', $linedUpIds))
            ->get();

        $this->attachCurrentSeason($players, $season->id);

        return $players;
    }

    /**
     * @return array{id: int, main_name: string, short_name: string, logo: string}
     */
    private static function team(Team $team): array
    {
        return [
            'id' => $team->id,
            'main_name' => $team->main_name,
            'short_name' => $team->short_name,
            'logo' => $team->toArray()['logo'],
        ];
    }

    /**
     * @param  list<RankedPlayer>  $ranked
     * @param  array<int, list<LineupManager>>|null  $linedUpBy  the full ranking's «Alineado por», keyed by player id
     * @return list<array<string, mixed>>
     */
    private function rows(array $ranked, ?array $linedUpBy = null): array
    {
        return array_map(fn (array $row): array => [
            'rank' => $row['rank'],
            'value' => $row['value'],
            'matches' => $row['matches'],
            'player' => [
                'id' => $row['player']->id,
                'nickname' => $row['player']->nickname,
                'image' => $row['player']->toArray()['image'],
                'position' => $row['player']->position,
                'team' => self::team($row['player']->team),
            ],
            ...($linedUpBy === null ? [] : ['lined_up_by' => $linedUpBy[$row['player']->id] ?? []]),
        ], $ranked);
    }
}
