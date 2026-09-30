<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\FixtureState;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Builds every value forecast row of a season with a handful of queries,
 * as plain scalars (no models), like season:backtest-max-bid does. Same
 * rules as the research export (comando-lechuga-research/value-forecast/
 * backtest.js): a player's matches are those of his current team, match
 * dates are the UTC date of `fixtures.date`, pending points count as 0.
 *
 * @phpstan-import-type MatchInfo from ValueForecastRow
 */
final class ValueForecastFeatures
{
    /** Days of values a row needs before its reference date (T − 3 … T). */
    private const int LOOKBACK_DAYS = 3;

    /** @var array<string, string> */
    private array $shifted = [];

    public function __construct(private readonly ValueForecastParameters $parameters = new ValueForecastParameters) {}

    /**
     * @return list<ValueForecastRow>
     */
    public function rows(Season $season, string $lastReferenceDate): array
    {
        $firstReference = $season->start_date->addDays($this->parameters->warmupDays)->toDateString();

        if ($firstReference > $lastReferenceDate) {
            return [];
        }

        $values = $this->values($this->shift($firstReference, -self::LOOKBACK_DAYS), $this->shift($lastReferenceDate, 1));
        $teams = DB::table('players')->pluck('team_id', 'id')->all();
        [$matchDates, $finishedDates] = $this->teamMatches($season);
        [$lineups, $playedPoints] = $this->lineups($season);
        $marketChanges = $this->marketChanges($values);
        $rows = [];

        foreach ($values as $playerId => $byDate) {
            $teamId = isset($teams[$playerId]) ? (int) $teams[$playerId] : null;

            if ($teamId === null) {
                continue;
            }

            foreach ($byDate as $date => $value) {
                if ($date < $firstReference || $date > $lastReferenceDate) {
                    continue;
                }

                $changeToday = self::change($byDate, $date, $this->shift($date, -1));
                $changeYesterday = self::change($byDate, $this->shift($date, -1), $this->shift($date, -2));
                $changeBefore = self::change($byDate, $this->shift($date, -2), $this->shift($date, -3));

                if ($changeToday === null || $changeYesterday === null || $changeBefore === null) {
                    continue;
                }

                $target = $this->shift($date, 1);
                $next = $byDate[$target] ?? null;

                $rows[] = new ValueForecastRow(
                    playerId: $playerId,
                    referenceDate: $date,
                    targetDate: $target,
                    value: $value,
                    changeToday: $changeToday,
                    changeYesterday: $changeYesterday,
                    changeBefore: $changeBefore,
                    marketChange: $marketChanges[$date] ?? 0.0,
                    matchYesterday: self::match($lineups[$playerId] ?? [], $finishedDates[$teamId] ?? [], $this->shift($date, -1)),
                    matchToday: self::match($lineups[$playerId] ?? [], $finishedDates[$teamId] ?? [], $date),
                    matchBefore: self::match($lineups[$playerId] ?? [], $finishedDates[$teamId] ?? [], $this->shift($date, -2)),
                    daysToNextMatch: self::daysToNextMatch($matchDates[$teamId] ?? [], $date),
                    averagePoints: self::averagePoints($playedPoints[$playerId] ?? [], $date),
                    nextValue: $next !== null && $next > 0 ? $next : null,
                );
            }
        }

        usort($rows, fn (ValueForecastRow $a, ValueForecastRow $b): int => [$a->referenceDate, $a->playerId] <=> [$b->referenceDate, $b->playerId]);

        return $rows;
    }

    /**
     * @return array<int, array<string, int>> player id → date → value, oldest first
     */
    private function values(string $from, string $to): array
    {
        $values = [];

        foreach (
            DB::table('player_markets')
                ->where('date', '>=', $from)
                ->where('date', '<=', $to)
                ->orderBy('date')
                ->select(['player_id', 'date', 'value'])
                ->cursor() as $row
        ) {
            $values[(int) $row->player_id][substr((string) $row->date, 0, 10)] = (int) $row->value;
        }

        return $values;
    }

    /**
     * Every match date of each team (any state, soonest first) and the dates
     * of its finished matches.
     *
     * @return array{0: array<int, list<string>>, 1: array<int, array<string, true>>}
     */
    private function teamMatches(Season $season): array
    {
        $all = [];
        $finished = [];

        foreach (
            DB::table('fixtures')
                ->where('season_id', $season->id)
                ->whereNotNull('date')
                ->orderBy('date')
                ->get(['date', 'team_local_id', 'team_guest_id', 'state']) as $fixture
        ) {
            $date = substr((string) $fixture->date, 0, 10);

            foreach ([(int) $fixture->team_local_id, (int) $fixture->team_guest_id] as $teamId) {
                $all[$teamId][] = $date;

                if ($fixture->state === FixtureState::Finished->value) {
                    $finished[$teamId][$date] = true;
                }
            }
        }

        return [$all, $finished];
    }

    /**
     * Each player's finished lineups by date (the first one of a date wins)
     * and, oldest first, the points of those he played minutes in.
     *
     * @return array{0: array<int, array<string, array{played: bool, points: int}>>, 1: array<int, list<array{date: string, points: int}>>}
     */
    private function lineups(Season $season): array
    {
        $byDate = [];
        $played = [];

        foreach (
            DB::table('fixture_lineups')
                ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
                ->where('fixtures.season_id', $season->id)
                ->where('fixtures.state', FixtureState::Finished->value)
                ->whereNotNull('fixture_lineups.player_id')
                ->orderBy('fixtures.date')
                ->orderBy('fixtures.id')
                ->get(['fixture_lineups.player_id', 'fixtures.date', 'fixture_lineups.fantasy_points', 'fixture_lineups.fantasy_stats']) as $lineup
        ) {
            $playerId = (int) $lineup->player_id;
            $date = substr((string) $lineup->date, 0, 10);
            $minutes = self::minutes($lineup->fantasy_stats);
            $points = (int) ($lineup->fantasy_points ?? 0);

            $byDate[$playerId][$date] ??= ['played' => $minutes > 0, 'points' => $points];

            if ($minutes > 0) {
                $played[$playerId][] = ['date' => $date, 'points' => $points];
            }
        }

        return [$byDate, $played];
    }

    /** Minutes played, from the provider's `fantasy_stats.mins_played[0]` (0 when absent). */
    private static function minutes(mixed $fantasyStats): int
    {
        $stats = is_string($fantasyStats) ? json_decode($fantasyStats, true) : null;
        $minutes = is_array($stats) && is_array($stats['mins_played'] ?? null) ? ($stats['mins_played'][0] ?? 0) : 0;

        return is_numeric($minutes) ? (int) $minutes : 0;
    }

    /**
     * Mean daily change of every player with values on a date and the day before.
     *
     * @param  array<int, array<string, int>>  $values
     * @return array<string, float>
     */
    private function marketChanges(array $values): array
    {
        $sums = [];
        $counts = [];

        foreach ($values as $byDate) {
            foreach (array_keys($byDate) as $date) {
                $change = self::change($byDate, $date, $this->shift($date, -1));

                if ($change === null) {
                    continue;
                }

                $sums[$date] = ($sums[$date] ?? 0.0) + $change;
                $counts[$date] = ($counts[$date] ?? 0) + 1;
            }
        }

        $means = [];

        foreach ($sums as $date => $sum) {
            $means[$date] = $sum / $counts[$date];
        }

        return $means;
    }

    /**
     * @param  array<string, int>  $byDate
     */
    private static function change(array $byDate, string $date, string $previousDate): ?float
    {
        $current = $byDate[$date] ?? 0;
        $previous = $byDate[$previousDate] ?? 0;

        return $current > 0 && $previous > 0 ? ($current - $previous) / $previous : null;
    }

    /**
     * @param  array<string, array{played: bool, points: int}>  $lineups
     * @param  array<string, true>  $finishedDates
     * @return MatchInfo
     */
    private static function match(array $lineups, array $finishedDates, string $date): array
    {
        $own = $lineups[$date] ?? null;

        if ($own !== null) {
            return ['team' => true, 'played' => $own['played'], 'points' => $own['points']];
        }

        return isset($finishedDates[$date])
            ? ['team' => true, 'played' => false, 'points' => 0]
            : ['team' => false, 'played' => false, 'points' => 0];
    }

    /**
     * Whole calendar days, counted in UTC so a daylight-saving change in the
     * app timezone can't shorten a day.
     *
     * @param  list<string>  $matchDates  soonest first
     */
    private static function daysToNextMatch(array $matchDates, string $date): int
    {
        foreach ($matchDates as $matchDate) {
            if ($matchDate > $date) {
                return min(30, (int) CarbonImmutable::parse($date, 'UTC')->diffInDays(CarbonImmutable::parse($matchDate, 'UTC')));
            }
        }

        return 30;
    }

    /**
     * @param  list<array{date: string, points: int}>  $played  oldest first
     */
    private static function averagePoints(array $played, string $date): float
    {
        $sum = 0;
        $count = 0;

        foreach ($played as $match) {
            if ($match['date'] >= $date) {
                break;
            }

            $sum += $match['points'];
            $count++;
        }

        return $count === 0 ? 0.0 : $sum / $count;
    }

    private function shift(string $date, int $days): string
    {
        return $this->shifted["{$date}|{$days}"] ??= CarbonImmutable::parse($date, 'UTC')->addDays($days)->toDateString();
    }
}
