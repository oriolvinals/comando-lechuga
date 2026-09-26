<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Enums\MatchResult;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Real LaLiga standings computed from the season's fixtures — shared by the
 * standings pages and the max bid model (which needs the table as it stood
 * on a past date, hence `$until`).
 */
class LeagueStandings
{
    public const array LIVE_STATES = [
        FixtureState::FirstHalf,
        FixtureState::HalfTime,
        FixtureState::SecondHalf,
    ];

    /**
     * Fixtures relevant to the standings table: finished matches plus any
     * currently live (in-progress) match — a live match counts provisionally
     * with its current score, the same way real LaLiga standings apps show
     * the table updating live during a jornada. `date` drives recent-form
     * ordering (not `week_number` — a jornada spans several days, and a
     * postponed match can be played out of its nominal week order).
     *
     * With `$until`, only fixtures dated on or before it count.
     *
     * @return Collection<int, Fixture>
     */
    public function fixtures(Season $season, ?CarbonInterface $until = null): Collection
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->whereIn('state', [
                FixtureState::Finished,
                ...self::LIVE_STATES,
            ])
            ->when($until !== null, fn ($query) => $query->where('date', '<=', $until))
            ->with(['localTeam', 'guestTeam'])
            ->get(['id', 'team_local_id', 'team_guest_id', 'local_score', 'guest_score', 'state', 'date']);
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
     * @param  Collection<int, Fixture>  $fixtures  from fixtures() — finished + live
     * @param  array<int, array{fixture_id: int, opponent: Team, is_home: bool, date: CarbonImmutable}>  $nextByTeam  keyed by team id
     * @return list<array{position: int, team: Team, played: int, won: int, drawn: int, lost: int, goals_for: int, goals_against: int, goal_difference: int, points: int, recent_form: list<array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: CarbonImmutable}>, live: array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: CarbonImmutable}|null, next: array{fixture_id: int, opponent: Team, is_home: bool, date: CarbonImmutable}|null}>
     */
    public function table(Collection $teams, Collection $fixtures, array $nextByTeam = []): array
    {
        $rows = $teams->map(function (Team $team) use ($fixtures, $nextByTeam): array {
            $played = 0;
            $won = 0;
            $drawn = 0;
            $lost = 0;
            $goalsFor = 0;
            $goalsAgainst = 0;
            $live = null;

            /** @var list<array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: CarbonImmutable}> $formEntries */
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
     * Each season team's 1-based position in the table, keyed by team id.
     *
     * @return array<int, int>
     */
    public function positions(Season $season, ?CarbonInterface $until = null): array
    {
        return collect($this->table($season->teams, $this->fixtures($season, $until)))
            ->mapWithKeys(fn (array $row): array => [$row['team']->id => $row['position']])
            ->all();
    }

    /**
     * @return array{fixture_id: int, opponent: Team, score: string, result: MatchResult, date: CarbonImmutable}
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
}
