<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use Carbon\CarbonImmutable;

/**
 * The public API's sense of "now" for a season. It answers:
 * - the state of a jornada;
 * - the next jornada whose lineup still has to be saved (it locks at the
 *   jornada's first kickoff, and buyouts close 24 h before that);
 * - the next match;
 * - the next daily market renewal (20:00 in Madrid, the time this league
 *   was created).
 *
 * Postponed fixtures never count. A postponed match keeps its jornada, but
 * neither the jornada's state nor its first kickoff looks at it.
 *
 * @phpstan-type UpcomingWeek array{week_number: int, lineup_locks_at: CarbonImmutable, buyouts_close_at: CarbonImmutable}
 */
class SeasonClock
{
    public const string TIMEZONE = 'Europe/Madrid';

    public const int MARKET_RENEWAL_HOUR = 20;

    public const int BUYOUT_BLACKOUT_HOURS = 24;

    public const string NOT_STARTED = 'not_started';

    public const string LIVE = 'live';

    public const string FINISHED = 'finished';

    /**
     * 'not_started' until one of the jornada's non-postponed matches kicks
     * off, 'finished' once all of them have finished, 'live' in between.
     *
     * @return 'not_started'|'live'|'finished'
     */
    public function weekState(Season $season, int $weekNumber): string
    {
        $states = Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $weekNumber)
            ->where('state', '!=', FixtureState::Postponed)
            ->get(['state'])
            ->map(fn (Fixture $fixture): FixtureState => $fixture->state);

        if ($states->every(fn (FixtureState $state): bool => $state === FixtureState::Scheduled)) {
            return self::NOT_STARTED;
        }

        return $states->every(fn (FixtureState $state): bool => $state === FixtureState::Finished)
            ? self::FINISHED
            : self::LIVE;
    }

    /**
     * The jornada's earliest kickoff among its non-postponed matches: its
     * lineup lock. Null when the jornada has no such match.
     */
    public function firstKickoff(Season $season, int $weekNumber): ?CarbonImmutable
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('week_number', $weekNumber)
            ->where('state', '!=', FixtureState::Postponed)
            ->orderBy('date')
            ->first(['date'])
            ?->date;
    }

    /**
     * The next jornada whose lineup can still be saved: the current one
     * while it hasn't kicked off, else the following one. Null after the
     * last jornada, or when that jornada has no match on the calendar.
     *
     * @return UpcomingWeek|null
     */
    public function upcomingWeek(Season $season): ?array
    {
        $weekNumber = $this->weekState($season, $season->current_week) === self::NOT_STARTED
            ? $season->current_week
            : $season->current_week + 1;

        if ($weekNumber > $season->total_weeks) {
            return null;
        }

        $lineupLocksAt = $this->firstKickoff($season, $weekNumber);

        if ($lineupLocksAt === null) {
            return null;
        }

        return [
            'week_number' => $weekNumber,
            'lineup_locks_at' => $lineupLocksAt,
            'buyouts_close_at' => $lineupLocksAt->subHours(self::BUYOUT_BLACKOUT_HOURS),
        ];
    }

    /**
     * Finished jornadas: every one before the current jornada (trusted as
     * past even with a postponed match left), plus the current one once it
     * has finished.
     *
     * @return list<int>
     */
    public function finishedWeekNumbers(Season $season): array
    {
        $weeks = $season->current_week > 1 ? range(1, $season->current_week - 1) : [];

        if ($this->weekState($season, $season->current_week) === self::FINISHED) {
            $weeks[] = $season->current_week;
        }

        return $weeks;
    }

    /**
     * The jornada a manager's "current" lineup belongs to: the current one
     * until it has finished, then the next (never past the last jornada).
     */
    public function lineupWeek(Season $season): int
    {
        if ($this->weekState($season, $season->current_week) !== self::FINISHED) {
            return $season->current_week;
        }

        return min($season->current_week + 1, $season->total_weeks);
    }

    /**
     * Buyouts are closed from 24 h before a jornada's first kickoff until
     * that kickoff; open at any other time.
     *
     * @param  UpcomingWeek|null  $upcomingWeek
     */
    public function buyoutsOpen(?array $upcomingWeek, CarbonImmutable $now): bool
    {
        if ($upcomingWeek === null) {
            return true;
        }

        return $now->lessThan($upcomingWeek['buyouts_close_at'])
            || $now->greaterThanOrEqualTo($upcomingWeek['lineup_locks_at']);
    }

    /**
     * The first scheduled match of any jornada that hasn't kicked off yet.
     */
    public function nextFixture(Season $season, CarbonImmutable $now): ?Fixture
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', $now)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->first();
    }

    /**
     * The next 20:00 in Madrid strictly after `$now`. It is computed on the
     * Madrid wall clock, so a clock change never moves it to 19:00 or 21:00.
     */
    public function nextMarketRenewal(CarbonImmutable $now): CarbonImmutable
    {
        $local = $now->setTimezone(self::TIMEZONE);
        $renewal = $local->setTime(self::MARKET_RENEWAL_HOUR, 0);

        return $renewal->greaterThan($local) ? $renewal : $renewal->addDay();
    }
}
