<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;

/**
 * The Equipos page's fixture-difficulty calendar: each LaLiga team's next
 * scheduled matches in date order — chronological per team rather than by
 * jornada, since a rescheduled match can be played after later jornadas —
 * each rival rated by its CURRENT real-table position
 * (LeagueStandings::difficulty), and teams ordered easiest run first.
 *
 * Postponed fixtures (no date to play them yet) are left out. Runs a single
 * query whatever the team or fixture count; rivals come from the table.
 */
class FixtureCalendar
{
    public const int MATCHES = 10;

    /**
     * `rescheduled` flags a match whose jornada comes before the previous
     * listed match's — a moved match showing up out of jornada order.
     *
     * @param  list<array{position: int, team: Team}>  $table  from LeagueStandings::table()
     * @return list<array{team: Team, position: int, average: float|null, matches: list<array{fixture_id: int, week_number: int, opponent: Team, is_home: bool, date: CarbonImmutable, rival_position: int, difficulty: float, rescheduled: bool}>}>
     */
    public function build(Season $season, array $table): array
    {
        $teamCount = count($table);
        $teamsById = [];
        $positions = [];

        foreach ($table as $row) {
            $teamsById[$row['team']->id] = $row['team'];
            $positions[$row['team']->id] = $row['position'];
        }

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->orderBy('date')
            ->orderBy('week_number')
            ->get(['id', 'week_number', 'team_local_id', 'team_guest_id', 'date']);

        /** @var array<int, list<array{fixture_id: int, week_number: int, opponent: Team, is_home: bool, date: CarbonImmutable, rival_position: int, difficulty: float, rescheduled: bool}>> $matchesByTeam */
        $matchesByTeam = [];

        foreach ($fixtures as $fixture) {
            foreach ([true, false] as $isHome) {
                $teamId = $isHome ? $fixture->team_local_id : $fixture->team_guest_id;
                $opponentId = $isHome ? $fixture->team_guest_id : $fixture->team_local_id;

                $listed = $matchesByTeam[$teamId] ?? [];

                if (!isset($teamsById[$teamId], $teamsById[$opponentId]) || count($listed) >= self::MATCHES) {
                    continue;
                }

                $previous = $listed === [] ? null : $listed[count($listed) - 1];
                $rivalPosition = $positions[$opponentId];

                $matchesByTeam[$teamId][] = [
                    'fixture_id' => $fixture->id,
                    'week_number' => $fixture->week_number,
                    'opponent' => $teamsById[$opponentId],
                    'is_home' => $isHome,
                    'date' => $fixture->date,
                    'rival_position' => $rivalPosition,
                    'difficulty' => round(LeagueStandings::difficulty($rivalPosition, $teamCount), 3),
                    'rescheduled' => $previous !== null && $fixture->week_number < $previous['week_number'],
                ];
            }
        }

        $rows = array_map(function (array $row) use ($matchesByTeam): array {
            $matches = $matchesByTeam[$row['team']->id] ?? [];
            $difficulties = array_column($matches, 'difficulty');

            return [
                'team' => $row['team'],
                'position' => $row['position'],
                'average' => $difficulties === [] ? null : round(array_sum($difficulties) / count($difficulties), 3),
                'matches' => $matches,
            ];
        }, $table);

        // Easiest run first; a team with no match left sinks to the bottom,
        // and ties keep the real-table order.
        usort($rows, fn (array $a, array $b): int => ($b['average'] ?? -INF) <=> ($a['average'] ?? -INF)
            ?: $a['position'] <=> $b['position']);

        return $rows;
    }
}
