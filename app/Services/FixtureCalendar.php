<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * The Equipos page's fixture-difficulty calendar: each LaLiga team's next
 * scheduled matches in date order — chronological per team rather than by
 * jornada, since a rescheduled match can be played after later jornadas —
 * each rival rated by MatchDifficulty's `general` variant (squad value and
 * recent performance, home/away), and teams ordered easiest run first (the
 * lowest average 0–10 difficulty).
 *
 * Postponed fixtures (no date to play them yet) are left out. Runs a single
 * fixtures query plus one MatchDifficulty::forMany() batch, whatever the
 * team or fixture count.
 */
class FixtureCalendar
{
    public const int MATCHES = 10;

    /**
     * The difficulty keys a match carries when its MatchDifficultyResult is
     * null — see build().
     *
     * @var array{difficulty: null, difficulty_variant: null, difficulty_components: array{}, absence_adjusted: null, rival_position: null}
     */
    private const array NULL_DIFFICULTY = [
        'difficulty' => null,
        'difficulty_variant' => null,
        'difficulty_components' => [],
        'absence_adjusted' => null,
        'rival_position' => null,
    ];

    public function __construct(private readonly MatchDifficulty $matchDifficulty) {}

    /**
     * `rescheduled` flags a match whose jornada comes before the previous
     * listed match's — a moved match showing up out of jornada order. A null
     * MatchDifficultyResult (fixture with no date, or a team outside it)
     * carries through as `difficulty: null` and the other difficulty keys
     * null/empty; such matches are excluded from the row's average.
     *
     * @param  list<array{position: int, team: Team}>  $table  from LeagueStandings::table()
     * @return list<array{team: Team, position: int, average: float|null, matches: list<array{fixture_id: int, week_number: int, opponent: Team, is_home: bool, date: CarbonImmutable, rescheduled: bool, difficulty: float|null, difficulty_variant: string|null, difficulty_components: array{rival_strength: float, home: float, absences: float}|array{}, absence_adjusted: bool|null, rival_position: int|null}>}>
     */
    public function build(Season $season, array $table): array
    {
        $teamsById = [];

        foreach ($table as $row) {
            $teamsById[$row['team']->id] = $row['team'];
        }

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->orderBy('date')
            ->orderBy('week_number')
            ->get(['id', 'season_id', 'week_number', 'team_local_id', 'team_guest_id', 'date']);

        /** @var array<int, int> $matchCountByTeam how many matches already accepted for each team, capped at self::MATCHES */
        $matchCountByTeam = [];

        /** @var array<int, int> $lastWeekByTeam the previously accepted match's week_number, for the rescheduled flag */
        $lastWeekByTeam = [];

        /** @var list<array{teamId: int, fixture: Fixture, opponent: Team, isHome: bool, rescheduled: bool}> $accepted */
        $accepted = [];

        foreach ($fixtures as $fixture) {
            foreach ([true, false] as $isHome) {
                $teamId = $isHome ? $fixture->team_local_id : $fixture->team_guest_id;
                $opponentId = $isHome ? $fixture->team_guest_id : $fixture->team_local_id;

                if (!isset($teamsById[$teamId], $teamsById[$opponentId]) || ($matchCountByTeam[$teamId] ?? 0) >= self::MATCHES) {
                    continue;
                }

                $previousWeek = $lastWeekByTeam[$teamId] ?? null;

                $accepted[] = [
                    'teamId' => $teamId,
                    'fixture' => $fixture,
                    'opponent' => $teamsById[$opponentId],
                    'isHome' => $isHome,
                    'rescheduled' => $previousWeek !== null && $fixture->week_number < $previousWeek,
                ];

                $matchCountByTeam[$teamId] = ($matchCountByTeam[$teamId] ?? 0) + 1;
                $lastWeekByTeam[$teamId] = $fixture->week_number;
            }
        }

        $results = $accepted === [] ? [] : $this->matchDifficulty->forMany(array_map(
            fn (array $entry): array => [$entry['fixture'], $entry['teamId'], DifficultyVariant::General],
            $accepted,
        ));

        /** @var array<int, list<array{fixture_id: int, week_number: int, opponent: Team, is_home: bool, date: CarbonImmutable, rescheduled: bool, difficulty: float|null, difficulty_variant: string|null, difficulty_components: array{rival_strength: float, home: float, absences: float}|array{}, absence_adjusted: bool|null, rival_position: int|null}>> $matchesByTeam */
        $matchesByTeam = [];

        foreach ($accepted as $index => $entry) {
            $matchesByTeam[$entry['teamId']][] = [
                'fixture_id' => $entry['fixture']->id,
                'week_number' => $entry['fixture']->week_number,
                'opponent' => $entry['opponent'],
                'is_home' => $entry['isHome'],
                'date' => $entry['fixture']->date,
                'rescheduled' => $entry['rescheduled'],
                ...($results[$index]?->toArray() ?? self::NULL_DIFFICULTY),
            ];
        }

        $rows = array_map(function (array $row) use ($matchesByTeam): array {
            $matches = $matchesByTeam[$row['team']->id] ?? [];
            $difficulties = array_values(array_filter(
                array_column($matches, 'difficulty'),
                fn (?float $difficulty): bool => $difficulty !== null,
            ));

            return [
                'team' => $row['team'],
                'position' => $row['position'],
                'average' => $difficulties === [] ? null : round(array_sum($difficulties) / count($difficulties), 3),
                'matches' => $matches,
            ];
        }, $table);

        // Easiest run first (lowest average difficulty); a team with no
        // match left sinks to the bottom, and ties keep the real-table order.
        usort($rows, fn (array $a, array $b): int => ($a['average'] ?? INF) <=> ($b['average'] ?? INF)
            ?: $a['position'] <=> $b['position']);

        return $rows;
    }
}
