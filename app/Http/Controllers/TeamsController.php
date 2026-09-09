<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesNextFixtures;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Models\Fixture;
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

    public function index(): Response
    {
        $season = Season::current();
        $teams = $season->teams;
        $fixtures = $this->standingsFixtures($season);

        return Inertia::render('teams/index', [
            'standings' => $this->standingsFor($teams, $fixtures),
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

        return Inertia::render('teams/show', [
            'team' => $team,
            'squad' => $squad,
            'standing' => $standing,
            'nextFixtures' => $nextFixtures,
            'fixtures' => $fixtures,
        ]);
    }

    /**
     * Fixtures relevant to the standings table: finished matches plus any
     * currently live (in-progress) match — a live match counts provisionally
     * with its current score, the same way real LaLiga standings apps show
     * the table updating live during a jornada.
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
            ->get(['team_local_id', 'team_guest_id', 'local_score', 'guest_score', 'state', 'week_number']);
    }

    /**
     * Real LaLiga standings computed from finished + live fixtures — points
     * desc, goal difference desc, goals for desc, then team name asc as a
     * stable final tiebreak (no head-to-head rule; good enough for display).
     *
     * @param  Collection<int, Team>  $teams
     * @param  Collection<int, Fixture>  $fixtures  from standingsFixtures() — finished + live
     * @return list<array{position: int, team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int, recent_form: list<'win'|'draw'|'loss'>, is_live: bool}>
     */
    private function standingsFor(Collection $teams, Collection $fixtures): array
    {
        $rows = $teams->map(function (Team $team) use ($fixtures): array {
            $played = 0;
            $won = 0;
            $drawn = 0;
            $lost = 0;
            $goalsFor = 0;
            $goalsAgainst = 0;
            $isLive = false;
            /** @var list<array{week_number: int, result: 'win'|'draw'|'loss'}> $formEntries */
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

                if ($for > $against) {
                    $won++;
                    $result = 'win';
                } elseif ($for === $against) {
                    $drawn++;
                    $result = 'draw';
                } else {
                    $lost++;
                    $result = 'loss';
                }

                if (in_array($fixture->state, self::LIVE_STATES, true)) {
                    $isLive = true;
                }

                if ($fixture->state === FixtureState::Finished) {
                    $formEntries[] = ['week_number' => $fixture->week_number, 'result' => $result];
                }
            }

            usort($formEntries, fn (array $a, array $b): int => $a['week_number'] <=> $b['week_number']);
            $recentForm = array_column(array_slice($formEntries, -5), 'result');

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
                'is_live' => $isLive,
            ];
        });

        return $rows
            ->sort(fn (array $a, array $b): int => $b['points'] <=> $a['points']
                ?: $b['goal_difference'] <=> $a['goal_difference']
                ?: $b['goals_for'] <=> $a['goals_for']
                ?: $a['team']->main_name <=> $b['team']->main_name)
            ->values()
            ->map(fn (array $row, int $index): array => ['position' => $index + 1, ...$row])
            ->all();
    }
}
