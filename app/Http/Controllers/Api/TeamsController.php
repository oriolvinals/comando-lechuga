<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FixtureState;
use App\Enums\MatchResult;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use App\Services\LeagueStandings;
use App\Services\StartProbabilities;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

/**
 * The real LaLiga table (finished + live matches, like /equipos). For each
 * team it adds the next match and that match's probable XI (FútbolFantasy)
 * or confirmed XI (worldcup26 first), with the real formation and each
 * player's %.
 */
class TeamsController extends Controller
{
    public function __construct(
        private readonly LeagueStandings $standings,
        private readonly StartProbabilities $startProbabilities,
    ) {}

    public function index(): JsonResponse
    {
        $season = Season::current();
        $nextByTeam = $this->nextFixtureByTeam($season);
        $table = $this->standings->table($season->teams, $this->standings->fixtures($season));

        $data = array_map(fn (array $row): array => [
            'rank' => $row['position'],
            'team' => (new TeamResource($row['team']))->resolve(),
            'played' => $row['played'],
            'won' => $row['won'],
            'drawn' => $row['drawn'],
            'lost' => $row['lost'],
            'goals_for' => $row['goals_for'],
            'goals_against' => $row['goals_against'],
            'goal_difference' => $row['goal_difference'],
            'points' => $row['points'],
            'recent_form' => array_map(fn (array $entry): array => $this->presentResult($entry), $row['recent_form']),
            'live' => $row['live'] === null ? null : $this->presentResult($row['live']),
            'next_fixture' => $this->presentNextFixture($row['team'], $nextByTeam[$row['team']->id] ?? null, $season),
        ], $table);

        return response()->json(['data' => $data]);
    }

    /**
     * @param  array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: CarbonImmutable}  $entry
     * @return array{fixture_id: int, opponent: array<string, mixed>, score: string, result: string, date: string}
     */
    private function presentResult(array $entry): array
    {
        return [
            'fixture_id' => $entry['fixture_id'],
            'opponent' => (new TeamResource($entry['opponent']))->resolve(),
            'score' => $entry['score'],
            'result' => $entry['result']->value,
            'date' => $entry['date']->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function presentNextFixture(Team $team, ?Fixture $fixture, Season $season): ?array
    {
        if ($fixture === null) {
            return null;
        }

        $isHome = $fixture->team_local_id === $team->id;
        $block = $this->startProbabilities->forTeamNextFixture($team, $season);

        return [
            'fixture_id' => $fixture->id,
            'url' => route('api.fixtures.show', $fixture->id),
            'week_number' => $fixture->week_number,
            'date' => $fixture->date->toIso8601String(),
            'opponent' => (new TeamResource($isHome ? $fixture->guestTeam : $fixture->localTeam))->resolve(),
            'is_home' => $isHome,
            'lineup' => $block === null || $block['fixture_id'] !== $fixture->id ? null : [
                'source' => $block['confirmed_source'] ?? 'futbolfantasy',
                'confirmed' => $block['confirmed_source'] !== null,
                'formation' => $block['formation'],
                'is_stale' => $block['is_stale'],
                'fetched_at' => $block['fetched_at'],
                'source_url' => $block['source_url'],
                'players' => array_map(fn (array $entry): array => [
                    'player' => [
                        'id' => $entry['player']->id,
                        'url' => route('api.players.show', $entry['player']->id),
                        'nickname' => $entry['player']->nickname,
                        'position' => $entry['player']->position?->value,
                    ],
                    'probability' => $entry['probability'],
                    'predicted_starter' => $entry['predicted_starter'],
                    'confirmed_starter' => $entry['confirmed_starter'],
                    'pitch_position' => $entry['pitch_position'],
                ], $block['players']),
            ],
        ];
    }

    /**
     * Each team's next match: the soonest scheduled fixture still to come.
     * This is the same rule StartProbabilities uses, so the lineup belongs
     * to this match.
     *
     * @return array<int, Fixture>
     */
    private function nextFixtureByTeam(Season $season): array
    {
        $nextByTeam = [];

        Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', now())
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get()
            ->each(function (Fixture $fixture) use (&$nextByTeam): void {
                foreach ([$fixture->team_local_id, $fixture->team_guest_id] as $teamId) {
                    $nextByTeam[$teamId] ??= $fixture;
                }
            });

        return $nextByTeam;
    }
}
