<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Enums\MatchPositionLine;
use App\Enums\MatchPositionSide;
use App\Enums\MatchResult;
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
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TeamsController extends Controller
{
    use AttachesCurrentPlayerSeason;
    use AttachesNextFixtures;
    use AttachesOwnerManager;
    use AttachesRecentScores;

    private const array LIVE_STATES = [
        FixtureState::FirstHalf,
        FixtureState::HalfTime,
        FixtureState::SecondHalf,
    ];

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

    public function index(): Response
    {
        $season = Season::current();
        $teams = $season->teams;
        $fixtures = $this->standingsFixtures($season);
        $nextByTeam = $this->nextFixtureByTeam($season);

        return Inertia::render('teams/index', [
            'standings' => $this->standingsFor($teams, $fixtures, $nextByTeam),
        ]);
    }

    public function show(Team $team): Response
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

        $standing = collect($this->standingsFor($season->teams, $this->standingsFixtures($season)))
            ->first(fn (array $row): bool => $row['team']->id === $team->id);

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
                ->map(fn (Fixture $fixture): array => [
                    'week_number' => $fixture->week_number,
                    'opponent' => $fixture->team_local_id === $team->id
                        ? $fixture->guestTeam
                        : $fixture->localTeam,
                    'is_home' => $fixture->team_local_id === $team->id,
                ])
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
            'nextFixtures' => $nextFixtures,
            'fixtures' => $fixtures,
            'currentWeek' => max($season->current_week, $latestLineupWeek),
            'weeklyLineups' => $weeklyLineups,
        ]);
    }

    /**
     * Fixtures relevant to the standings table: finished matches plus any
     * currently live (in-progress) match — a live match counts provisionally
     * with its current score, the same way real LaLiga standings apps show
     * the table updating live during a jornada. `date` drives recent-form
     * ordering (not `week_number` — a jornada spans several days, and a
     * postponed match can be played out of its nominal week order).
     *
     * @return Collection<int, Fixture>
     */
    private function standingsFixtures(Season $season): Collection
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->whereIn('state', [
                FixtureState::Finished,
                ...self::LIVE_STATES,
            ])
            ->with(['localTeam', 'guestTeam'])
            ->get(['id', 'team_local_id', 'team_guest_id', 'local_score', 'guest_score', 'state', 'date']);
    }

    /**
     * Each team's single soonest scheduled fixture — used as the standings
     * table's "next match" slot for a team that isn't currently live.
     *
     * @return array<int, array{fixture_id: int, opponent: Team, is_home: bool, date: \Carbon\CarbonImmutable}>
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
     * Real LaLiga standings computed from finished + live fixtures — points
     * desc, goal difference desc, goals for desc, then team name asc as a
     * stable final tiebreak (no head-to-head rule; good enough for display).
     *
     * `recent_form` holds up to the last 4 FINISHED results, newest first —
     * the "what's happening right now" slot (live score, or else the next
     * scheduled match) is deliberately separate (`live`/`next`) rather than
     * a 5th form entry, since it isn't a settled result yet.
     *
     * @param  Collection<int, Team>  $teams
     * @param  Collection<int, Fixture>  $fixtures  from standingsFixtures() — finished + live
     * @param  array<int, array{fixture_id: int, opponent: Team, is_home: bool, date: \Carbon\CarbonImmutable}>  $nextByTeam  from nextFixtureByTeam(), keyed by team id
     * @return list<array{position: int, team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int, recent_form: list<array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: \Carbon\CarbonImmutable}>, live: array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: \Carbon\CarbonImmutable}|null, next: array{fixture_id: int, opponent: Team, is_home: bool, date: \Carbon\CarbonImmutable}|null}>
     */
    private function standingsFor(Collection $teams, Collection $fixtures, array $nextByTeam = []): array
    {
        $rows = $teams->map(function (Team $team) use ($fixtures, $nextByTeam): array {
            $played = 0;
            $won = 0;
            $drawn = 0;
            $lost = 0;
            $goalsFor = 0;
            $goalsAgainst = 0;
            $live = null;
            /** @var list<array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: \Carbon\CarbonImmutable}> $formEntries */
            $formEntries = [];

            foreach ($fixtures as $fixture) {
                $isLocal = $fixture->team_local_id === $team->id;
                $isGuest = $fixture->team_guest_id === $team->id;

                if (!$isLocal && !$isGuest) {
                    continue;
                }

                $played++;
                $for = ($isLocal ? $fixture->local_score : $fixture->guest_score) ?? 0;
                $against = ($isLocal ? $fixture->guest_score : $fixture->local_score) ?? 0;
                $goalsFor += $for;
                $goalsAgainst += $against;
                $opponent = $isLocal ? $fixture->guestTeam : $fixture->localTeam;
                $result = MatchResult::fromScore($for, $against);

                if ($result === MatchResult::Win) {
                    $won++;
                } elseif ($result === MatchResult::Draw) {
                    $drawn++;
                } else {
                    $lost++;
                }

                $entry = $this->formEntry($fixture, $opponent, $for, $against);

                if (in_array($fixture->state, self::LIVE_STATES, true)) {
                    $live = $entry;
                }

                if ($fixture->state === FixtureState::Finished) {
                    $formEntries[] = $entry;
                }
            }

            usort($formEntries, fn (array $a, array $b): int => $b['date'] <=> $a['date']);
            $recentForm = array_slice($formEntries, 0, 4);

            return [
                'team' => $team,
                'played' => $played,
                'won' => $won,
                'drawn' => $drawn,
                'lost' => $lost,
                'goals_for' => $goalsFor,
                'goals_against' => $goalsAgainst,
                'goal_difference' => $goalsFor - $goalsAgainst,
                'points' => $won * 3 + $drawn,
                'recent_form' => $recentForm,
                'live' => $live,
                'next' => $live === null ? ($nextByTeam[$team->id] ?? null) : null,
            ];
        });

        return array_values($rows
            ->sort(fn (array $a, array $b): int => $b['points'] <=> $a['points']
                ?: $b['goal_difference'] <=> $a['goal_difference']
                ?: $b['goals_for'] <=> $a['goals_for']
                ?: $a['team']->main_name <=> $b['team']->main_name)
            ->values()
            ->map(fn (array $row, int $index): array => ['position' => $index + 1, ...$row])
            ->all());
    }

    /**
     * @return array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: \Carbon\CarbonImmutable}
     */
    private function formEntry(Fixture $fixture, Team $opponent, int $for, int $against): array
    {
        return [
            'fixture_id' => $fixture->id,
            'opponent' => $opponent,
            'score' => "{$for}-{$against}",
            'result' => MatchResult::fromScore($for, $against),
            'date' => $fixture->date,
        ];
    }

    /**
     * One entry per jornada that already has a synced starting XI for this
     * team — a jornada with no Fixture yet, or a Fixture with no starters
     * synced, is simply absent (the frontend shows an empty state for any
     * selected week that isn't in this list).
     *
     * `fixture_lineups.player_id` is nullable (an unresolved worldcup26
     * roster entry — see the match-data-linking design docs): a starter row
     * with no resolved `Player`, or whose `Player` has no current-season
     * `PlayerSeason` (so no known position), is dropped here rather than
     * crashing on `$lineup->player->position`, since there's nothing
     * displayable for it on this pitch (no name/photo/position) anyway.
     *
     * @param  Collection<int, Fixture>  $fixtures  this team's fixtures for the season, with localTeam/guestTeam loaded
     * @return list<array{week_number: int, fixture: Fixture, players: list<array{id: int, points: int|null, stats: array<string, mixed>|null, position: PlayerPosition, player: Player, match_finished: bool, pitch_top: float, pitch_left: float}>}>
     */
    private function weeklyLineupsFor(Team $team, Season $season, Collection $fixtures): array
    {
        $fixturesById = $fixtures->keyBy('id');

        $startersByFixture = FixtureLineup::query()
            ->whereIn('fixture_id', $fixturesById->keys())
            ->where('team_id', $team->id)
            ->where('starter', true)
            ->whereNotNull('player_id')
            ->with('player.team')
            ->get()
            ->groupBy('fixture_id');

        $this->attachCurrentSeason(
            $startersByFixture->flatten()->pluck('player')->unique('id'),
            $season->id,
        );

        $weeklyLineups = [];

        foreach ($startersByFixture as $fixtureId => $starters) {
            $fixture = $fixturesById->get($fixtureId);

            if ($fixture === null || $starters->isEmpty()) {
                continue;
            }

            $players = [];

            foreach ($starters as $lineup) {
                $player = $lineup->player;

                if (!$player instanceof Player || !$player->position instanceof PlayerPosition) {
                    continue;
                }

                $players[] = [
                    'id' => $lineup->id,
                    'points' => $lineup->fantasy_points,
                    'stats' => $lineup->fantasy_stats,
                    'position' => $player->position,
                    'player' => $player,
                    'match_finished' => $fixture->state === FixtureState::Finished,
                    'pitch_top' => $this->pitchTop($lineup, $starters),
                    'pitch_left' => $this->pitchLeft($lineup, $starters),
                ];
            }

            if ($players === []) {
                continue;
            }

            $weeklyLineups[] = [
                'week_number' => $fixture->week_number,
                'fixture' => $fixture,
                'players' => $players,
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

    private function pitchSideOrder(string $position): int
    {
        return match (MatchPositionSide::fromWorldcup26Text($position)) {
            MatchPositionSide::Left => 0,
            MatchPositionSide::Center => 1,
            MatchPositionSide::Right => 2,
        };
    }
}
