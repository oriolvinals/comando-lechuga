<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Services\SeasonClock;
use Illuminate\Support\Facades\DB;

test('asks the database once per jornada for its state and first kickoff, shared across the request', function (): void {
    $season = Season::factory()->create(['current_week' => 7]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 7,
        'state' => FixtureState::Scheduled,
        'date' => now()->addDay(),
    ]);

    DB::enableQueryLog();

    $clock = app(SeasonClock::class);
    $clock->weekState($season, 7);
    $clock->firstKickoff($season, 7);
    $clock->upcomingWeek($season);
    $clock->lineupWeek($season);

    $sameRequestClock = app(SeasonClock::class);
    $sameRequestClock->weekState($season, 7);
    $sameRequestClock->firstKickoff($season, 7);

    expect($sameRequestClock)->toBe($clock)
        ->and(DB::getQueryLog())->toHaveCount(2);

    $clock->weekState($season, 8);

    expect(DB::getQueryLog())->toHaveCount(3);
});

/**
 * @param  list<string>  $kickoffs
 */
function jornadaAt(Season $season, int $weekNumber, array $kickoffs, FixtureState $state = FixtureState::Finished): void
{
    foreach ($kickoffs as $kickoff) {
        Fixture::factory()->create(['season_id' => $season->id, 'week_number' => $weekNumber, 'date' => $kickoff, 'state' => $state]);
    }
}

test('locks lineups at the first kickoff of a normal jornada', function (): void {
    $season = Season::factory()->create();
    jornadaAt($season, 5, ['2026-09-12 14:00:00', '2026-09-11 21:00:00', '2026-09-13 21:00:00', '2026-09-14 21:00:00']);

    expect(app(SeasonClock::class)->lineupLock($season, 5)?->toDateTimeString())->toBe('2026-09-11 21:00:00');
});

test('locks lineups at the main block, ignoring a match played early', function (): void {
    $season = Season::factory()->create();
    jornadaAt($season, 6, ['2026-09-03 21:00:00', '2026-09-15 19:00:00', '2026-09-15 21:30:00', '2026-09-16 19:00:00', '2026-09-17 21:30:00']);

    $clock = app(SeasonClock::class);

    expect($clock->lineupLock($season, 6)?->toDateTimeString())->toBe('2026-09-15 19:00:00')
        ->and($clock->firstKickoff($season, 6)?->toDateTimeString())->toBe('2026-09-03 21:00:00');
});

test('locks lineups at the main block when a match is rescheduled weeks later', function (): void {
    $season = Season::factory()->create();
    jornadaAt($season, 1, ['2026-08-15 19:30:00', '2026-08-16 17:00:00', '2026-08-17 21:00:00', '2026-08-19 21:00:00', '2026-08-25 21:00:00', '2026-08-27 21:00:00']);
    jornadaAt($season, 1, ['2026-10-21 20:00:00'], FixtureState::Scheduled);

    expect(app(SeasonClock::class)->lineupLock($season, 1)?->toDateTimeString())->toBe('2026-08-15 19:30:00');
});

test('leaves postponed matches out of the lineup lock', function (): void {
    $season = Season::factory()->create();
    jornadaAt($season, 3, ['2026-08-28 19:00:00'], FixtureState::Postponed);
    jornadaAt($season, 3, ['2026-08-29 17:00:00', '2026-08-30 19:30:00']);
    jornadaAt($season, 4, ['2026-09-04 21:00:00'], FixtureState::Postponed);

    $clock = app(SeasonClock::class);

    expect($clock->lineupLock($season, 3)?->toDateTimeString())->toBe('2026-08-29 17:00:00')
        ->and($clock->lineupLock($season, 4))->toBeNull();
});

test('ignores a match brought forward into the previous jornada, however close to the main block', function (): void {
    $season = Season::factory()->create();
    jornadaAt($season, 9, ['2026-10-16 21:00:00', '2026-10-17 18:30:00', '2026-10-18 21:00:00', '2026-10-19 21:00:00']);
    jornadaAt($season, 10, ['2026-10-18 16:15:00', '2026-10-19 21:00:00', '2026-10-20 19:00:00', '2026-10-21 21:30:00', '2026-10-22 21:30:00']);

    $clock = app(SeasonClock::class);

    expect($clock->lineupLock($season, 10)?->toDateTimeString())->toBe('2026-10-20 19:00:00')
        ->and($clock->lineupLock($season, 9)?->toDateTimeString())->toBe('2026-10-16 21:00:00');
});

test('a match brought forward after the previous jornada ended locks the jornada', function (): void {
    $season = Season::factory()->create();
    jornadaAt($season, 9, ['2026-10-16 21:00:00', '2026-10-19 21:00:00']);
    jornadaAt($season, 10, ['2026-10-21 19:00:00', '2026-10-23 21:00:00', '2026-10-24 18:30:00', '2026-10-25 21:00:00']);

    expect(app(SeasonClock::class)->lineupLock($season, 10)?->toDateTimeString())->toBe('2026-10-21 19:00:00');
});
