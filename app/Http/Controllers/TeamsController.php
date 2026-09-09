<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TeamsController extends Controller
{
    public function index(): Response
    {
        $season = Season::current();
        $teams = $season->teams;
        $fixtures = $this->finishedFixtures($season);

        return Inertia::render('teams/index', [
            'standings' => $this->standingsFor($teams, $fixtures),
        ]);
    }

    public function show(Team $team): Response
    {
        return Inertia::render('teams/show', [
            'team' => $team,
        ]);
    }

    /**
     * @return Collection<int, Fixture>
     */
    private function finishedFixtures(Season $season): Collection
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->get(['team_local_id', 'team_guest_id', 'local_score', 'guest_score']);
    }

    /**
     * Real LaLiga standings computed from finished fixtures — points desc,
     * goal difference desc, goals for desc, then team name asc as a stable
     * final tiebreak (no head-to-head rule; good enough for display).
     *
     * @param  Collection<int, Team>  $teams
     * @param  Collection<int, Fixture>  $fixtures
     * @return list<array{position: int, team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int}>
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
                } elseif ($for === $against) {
                    $drawn++;
                } else {
                    $lost++;
                }
            }

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
