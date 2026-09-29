<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesNextFixtures;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use App\Services\DaznEstimatePresenter;
use App\Services\FixtureCalendar;
use App\Services\LeagueStandings;
use App\Services\StartProbabilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TeamsController extends Controller
{
    use AttachesCurrentPlayerSeason;
    use AttachesNextFixtures;
    use AttachesOwnerManager;
    use AttachesRecentScores;

    /** Equipos tab values for `?vista`. */
    public const string STANDINGS_VIEW = 'clasificacion';

    public const string CALENDAR_VIEW = 'calendario';

    public function __construct(private readonly LeagueStandings $standings) {}

    // Vertical anchors (top %) for the ficha's own portrait pitch, matching
    // HqLineupPitch's ROWS constant. Unlike the fantasy `Player::position`
    // column (only 4 broad buckets), a real formation can use more than one
    // midfield line (e.g. 4-2-3-1's double pivot + advanced trio) — any
    // midfield line beyond a flat single one splits evenly between the
    // defender and forward anchors, by however many this team's own lineup
    // actually uses. Keyed by MatchPositionLine value.
    /** @var array<string, int> */
    private const array PITCH_ROW_ANCHOR = [
        'goalkeeper' => 6,
        'defender' => 28,
        'forward' => 74,
    ];

    // Front-to-back order of the possible midfield lines — see PITCH_ROW_ANCHOR.
    /** @var list<string> */
    private const array PITCH_MIDFIELD_LINE_ORDER = [
        'defensive_midfielder',
        'midfielder',
        'attacking_midfielder',
    ];

    // Same per-player horizontal spacing FixturesController's shared match
    // pitch uses, so a line of starters here spreads the same way.
    private const float PITCH_LINE_STEP = 76 / 3;

    /**
     * The standings table always ships (the header and the calendar both need
     * it); the fixture-difficulty calendar is only computed and sent on
     * `?vista=calendario`, the Calendario tab.
     */
    public function index(Request $request, FixtureCalendar $calendar): Response
    {
        $season = Season::current();
        $teams = $season->teams;
        $fixtures = $this->standings->fixtures($season);
        $nextByTeam = $this->nextFixtureByTeam($season);
        $standings = $this->standings->table($teams, $fixtures, $nextByTeam);
        $isCalendarView = $request->query('vista') === self::CALENDAR_VIEW;

        $props = [
            'standings' => $standings,
            'view' => $isCalendarView ? self::CALENDAR_VIEW : self::STANDINGS_VIEW,
        ];

        if ($isCalendarView) {
            $props['calendar'] = $calendar->build($season, $standings);
        }

        return Inertia::render('teams/index', $props);
    }

    public function show(Team $team, StartProbabilities $startProbabilities): Response
    {
        $season = Season::current();

        $squad = Player::query()
            ->select('players.*')
            ->join('player_seasons', function ($join) use ($season): void {
                $join->on('player_seasons.player_id', '=', 'players.id')
                    ->where('player_seasons.season_id', $season->id);
            })
            ->where('team_id', $team->id)
            ->whereNotNull('fantasy_id')
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->with('team')
            ->orderByDesc('player_seasons.points')
            ->get();

        $this->attachOwnerManager($squad, $season->id);
        $this->attachCurrentSeason($squad, $season->id);
        $this->attachRecentScores($squad, $season);
        $this->attachNextFixtures($squad, $season);

        $table = collect($this->standings->table($season->teams, $this->standings->fixtures($season)));
        $standing = $table->first(fn (array $row): bool => $row['team']->id === $team->id);

        /** @var array<int, int> $positions */
        $positions = $table->mapWithKeys(fn (array $row): array => [$row['team']->id => $row['position']])->all();
        $nextFixtures = array_pad(
            Fixture::query()
                ->where('season_id', $season->id)
                ->where('state', FixtureState::Scheduled)
                ->where(fn ($query) => $query
                    ->where('team_local_id', $team->id)
                    ->orWhere('team_guest_id', $team->id))
                ->with(['localTeam', 'guestTeam'])
                ->orderBy('date')
                ->take(3)
                ->get()
                ->map(fn (Fixture $fixture): array => $this->nextFixtureSlot($fixture, $team->id, $positions))
                ->values()
                ->all(),
            3,
            null,
        );

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where(fn ($query) => $query
                ->where('team_local_id', $team->id)
                ->orWhere('team_guest_id', $team->id))
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('week_number')
            ->get();

        $weeklyLineups = $this->weeklyLineupsFor($team, $season, $fixtures);
        $latestLineupWeek = collect($weeklyLineups)->max('week_number') ?? 0;

        return Inertia::render('teams/show', [
            'team' => $team,
            'squad' => $squad,
            'standing' => $standing,
            'perMatch' => $this->perMatchRates($standing),
            'squadSummary' => [
                'owned_count' => $squad->filter(fn (Player $player): bool => $player->owner_manager !== null)->count(),
                'fantasy_points' => (int) $squad->sum('points'),
            ],
            'nextFixtures' => $nextFixtures,
            'fixtures' => $fixtures,
            'currentWeek' => max($season->current_week, $latestLineupWeek),
            'weeklyLineups' => $weeklyLineups,
            'startProbabilities' => $startProbabilities->forTeamNextFixture($team, $season),
        ]);
    }

    /**
     * LaLiga goals scored and points won per match played — null until the team has played.
     *
     * @param  array{played: int, goals_for: int, points: int}|null  $standing
     * @return array{goals_for: float, points: float}|null
     */
    private function perMatchRates(?array $standing): ?array
    {
        if ($standing === null || $standing['played'] === 0) {
            return null;
        }

        return [
            'goals_for' => round($standing['goals_for'] / $standing['played'], 2),
            'points' => round($standing['points'] / $standing['played'], 2),
        ];
    }

    /**
     * Each team's single soonest scheduled fixture — used as the standings
     * table's "next match" slot for a team that isn't currently live.
     *
     * @return array<int, array{fixture_id: int, opponent: Team, is_home: bool, date: CarbonImmutable}>
     */
    private function nextFixtureByTeam(Season $season): array
    {
        $nextByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get()
            ->each(function (Fixture $fixture) use (&$nextByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    if (isset($nextByTeam[$teamId])) {
                        continue;
                    }

                    $nextByTeam[$teamId] = [
                        'fixture_id' => $fixture->id,
                        'opponent' => $fixture->team_local_id === $teamId ? $fixture->guestTeam : $fixture->localTeam,
                        'is_home' => $fixture->team_local_id === $teamId,
                        'date' => $fixture->date,
                    ];
                }
            });

        return $nextByTeam;
    }

    /**
     * One entry per jornada that already has a synced starting XI for this
     * team — a jornada with no Fixture yet, or a Fixture with no starters
     * synced, is simply absent (the frontend shows an empty state for any
     * selected week that isn't in this list). Bench players for that same
     * jornada ride along under `substitutes` — they never get pitch
     * coordinates (those only make sense for a real match line), but still
     * carry `starter`/`subbed_out`/`sub_minute` so the frontend can badge
     * them the same way as the starters.
     *
     * `fixture_lineups.player_id` is nullable (an unresolved worldcup26
     * roster entry — see the match-data-linking design docs): a lineup row
     * with no resolved `Player`, or whose `Player` has no current-season
     * `PlayerSeason` (so no known position), is dropped here rather than
     * crashing on `$lineup->player->position`, since there's nothing
     * displayable for it on this pitch (no name/photo/position) anyway.
     *
     * @param  Collection<int, Fixture>  $fixtures  this team's fixtures for the season, with localTeam/guestTeam loaded
     * @return list<array{week_number: int, fixture: Fixture, players: list<array{id: int, points: int|null, stats: array<string, mixed>|null, position: PlayerPosition, player: Player, match_finished: bool, starter: bool, subbed_out: bool, sub_minute: int|null, pitch_top: float, pitch_left: float}>, substitutes: list<array{id: int, points: int|null, stats: array<string, mixed>|null, position: PlayerPosition, player: Player, match_finished: bool, starter: bool, subbed_out: bool, sub_minute: int|null}>}>
     */
    private function weeklyLineupsFor(Team $team, Season $season, Collection $fixtures): array
    {
        $fixturesById = $fixtures->keyBy('id');

        $lineupsByFixture = FixtureLineup::query()
            ->whereIn('fixture_id', $fixturesById->keys())
            ->where('team_id', $team->id)
            ->whereNotNull('player_id')
            ->with('player.team')
            ->get()
            ->groupBy('fixture_id');

        $this->attachCurrentSeason(
            $lineupsByFixture->flatten()->pluck('player')->unique('id'),
            $season->id,
        );

        $weeklyLineups = [];

        foreach ($lineupsByFixture as $fixtureId => $lineupRows) {
            $fixture = $fixturesById->get($fixtureId);

            if ($fixture === null || $lineupRows->isEmpty()) {
                continue;
            }

            $starters = $lineupRows->where('starter', true)->values();

            $players = [];
            $substitutes = [];

            foreach ($lineupRows as $lineup) {
                $player = $lineup->player;

                if (!$player instanceof Player || !$player->position instanceof PlayerPosition) {
                    continue;
                }

                $entry = [
                    'id' => $lineup->id,
                    'points' => $lineup->fantasy_points,
                    'stats' => $lineup->fantasy_stats,
                    'position' => $player->position,
                    'player' => $player,
                    'match_finished' => $fixture->state === FixtureState::Finished,
                    'fixture' => $fixture,
                    'starter' => $lineup->starter,
                    'subbed_out' => $lineup->subbed_out,
                    'sub_minute' => $lineup->sub_minute,
                    ...DaznEstimatePresenter::present($lineup, $fixture),
                ];

                if ($lineup->starter) {
                    $entry['pitch_top'] = $this->pitchTop($lineup, $starters);
                    $entry['pitch_left'] = $this->pitchLeft($lineup, $starters);
                    $players[] = $entry;
                } else {
                    $substitutes[] = $entry;
                }
            }

            if ($players === []) {
                continue;
            }

            // Played subs first, then unused bench — same convention as
            // HqFixtureBench on the match ficha.
            usort($substitutes, fn (array $a, array $b): int => (int) ($b['sub_minute'] !== null) <=> (int) ($a['sub_minute'] !== null));

            $weeklyLineups[] = [
                'week_number' => $fixture->week_number,
                'fixture' => $fixture,
                'players' => $players,
                'substitutes' => $substitutes,
            ];
        }

        usort($weeklyLineups, fn (array $a, array $b): int => $a['week_number'] <=> $b['week_number']);

        return $weeklyLineups;
    }

    /**
     * Vertical anchor (top %) for a starter based on their real match role,
     * parsed from the raw worldcup26 position text — see PITCH_ROW_ANCHOR.
     *
     * @param  Collection<int, FixtureLineup>  $teamStarters  this team's own starters for the fixture
     */
    private function pitchTop(FixtureLineup $lineup, Collection $teamStarters): float
    {
        $line = MatchPositionLine::fromWorldcup26Text($lineup->position);

        if (isset(self::PITCH_ROW_ANCHOR[$line->value])) {
            return (float) self::PITCH_ROW_ANCHOR[$line->value];
        }

        $midfieldLines = $teamStarters
            ->map(fn (FixtureLineup $mate): string => MatchPositionLine::fromWorldcup26Text($mate->position)->value)
            ->filter(fn (string $value): bool => in_array($value, self::PITCH_MIDFIELD_LINE_ORDER, true))
            ->unique()
            ->sort(fn (string $a, string $b): int => array_search($a, self::PITCH_MIDFIELD_LINE_ORDER, true) <=> array_search($b, self::PITCH_MIDFIELD_LINE_ORDER, true))
            ->values();

        $index = $midfieldLines->search(fn (string $value): bool => $value === $line->value);
        $count = $midfieldLines->count();
        $defenderDepth = self::PITCH_ROW_ANCHOR['defender'];
        $forwardDepth = self::PITCH_ROW_ANCHOR['forward'];

        if ($index === false || $count === 0) {
            return (float) (($defenderDepth + $forwardDepth) / 2);
        }

        $step = ($forwardDepth - $defenderDepth) / ($count + 1);

        return (float) ($defenderDepth + ($step * ($index + 1)));
    }

    /**
     * Horizontal spread (left %) among starters sharing the same real match
     * line, ordered left-to-right by side then shirt number — same spacing
     * FixturesController's shared match pitch uses.
     *
     * @param  Collection<int, FixtureLineup>  $teamStarters  this team's own starters for the fixture
     */
    private function pitchLeft(FixtureLineup $lineup, Collection $teamStarters): float
    {
        $line = MatchPositionLine::fromWorldcup26Text($lineup->position);

        $lineMates = $teamStarters
            ->filter(fn (FixtureLineup $mate): bool => MatchPositionLine::fromWorldcup26Text($mate->position) === $line)
            ->sortBy([
                fn (FixtureLineup $a, FixtureLineup $b): int => $this->pitchSideOrder($a->position) <=> $this->pitchSideOrder($b->position),
                fn (FixtureLineup $a, FixtureLineup $b): int => $a->jersey <=> $b->jersey,
            ])
            ->values();

        $index = $lineMates->search(fn (FixtureLineup $mate): bool => $mate->id === $lineup->id);
        $count = $lineMates->count();

        if ($count <= 1) {
            return 50.0;
        }

        $index = $index === false ? 0 : $index;
        $step = min(self::PITCH_LINE_STEP, 76 / ($count - 1));
        $span = $step * ($count - 1);
        $start = 50 - ($span / 2);

        return round($start + ($index * $step), 1);
    }

    /**
     * Screen order (left to right) of a player's side. The goalkeeper sits at
     * the top of this pitch, so the team attacks down the screen: seen from
     * the goalkeeper, the player's right is the screen's left.
     */
    private function pitchSideOrder(string $position): int
    {
        return 4 - MatchPositionSide::fromWorldcup26Text($position)->leftToRight();
    }
}
