<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-28 12:00:00', 'Europe/Madrid'));
});

function clockSeason(int $currentWeek, int $totalWeeks = 38): Season
{
    return Season::factory()->create([
        'name' => 'LaLiga 26/27',
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(200),
        'current_week' => $currentWeek,
        'total_weeks' => $totalWeeks,
    ]);
}

function clockFixture(Season $season, int $weekNumber, FixtureState $state, CarbonInterface $date): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => $weekNumber,
        'state' => $state,
        'date' => $date,
    ]);
}

test('describes a jornada that has not kicked off yet, ignoring a postponed match', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 6, FixtureState::Finished, now()->subDays(6));
    clockFixture($season, 7, FixtureState::Postponed, now()->addDay()->setTime(18, 0));
    $lock = now()->addDays(2)->setTime(18, 30);
    $first = clockFixture($season, 7, FixtureState::Scheduled, $lock);
    clockFixture($season, 7, FixtureState::Scheduled, now()->addDays(3)->setTime(21, 0));

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.name', 'LaLiga 26/27');
    $response->assertJsonPath('data.total_weeks', 38);
    $response->assertJsonPath('data.current_week', 7);
    $response->assertJsonPath('data.current_week_state', 'not_started');
    $response->assertJsonPath('data.upcoming_week.week_number', 7);
    $response->assertJsonPath('data.upcoming_week.lineup_locks_at', $lock->toIso8601String());
    $response->assertJsonPath('data.upcoming_week.buyouts_close_at', $lock->subHours(24)->toIso8601String());
    $response->assertJsonPath('data.upcoming_week.buyouts_reopen_at', $lock->toIso8601String());
    $response->assertJsonPath('data.buyouts_open', true);
    $response->assertJsonPath('data.next_fixture.id', $first->id);
    $response->assertJsonPath('data.next_fixture.week_number', 7);
});

test('closes buyouts in the 24 hours before the jornada\'s first kickoff', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 7, FixtureState::Scheduled, now()->addHours(10));

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.buyouts_open', false);
});

test('moves the upcoming jornada on once the current one is live', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 7, FixtureState::FirstHalf, now()->subMinutes(20));
    clockFixture($season, 7, FixtureState::Scheduled, now()->addDay());
    $nextLock = now()->addDays(5)->setTime(16, 15);
    clockFixture($season, 8, FixtureState::Scheduled, $nextLock);

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.current_week_state', 'live');
    $response->assertJsonPath('data.upcoming_week.week_number', 8);
    $response->assertJsonPath('data.upcoming_week.lineup_locks_at', $nextLock->toIso8601String());
});

test('marks a jornada finished when every match but a postponed one has finished', function (): void {
    $season = clockSeason(7);
    clockFixture($season, 7, FixtureState::Finished, now()->subDay());
    clockFixture($season, 7, FixtureState::Postponed, now()->subDays(2));
    clockFixture($season, 8, FixtureState::Scheduled, now()->addDays(4));

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.current_week_state', 'finished');
    $response->assertJsonPath('data.upcoming_week.week_number', 8);
});

test('has no upcoming jornada, next match or buyout window after the last jornada', function (): void {
    $season = clockSeason(38);
    clockFixture($season, 38, FixtureState::Finished, now()->subDay());

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.current_week_state', 'finished');
    $response->assertJsonPath('data.upcoming_week', null);
    $response->assertJsonPath('data.next_fixture', null);
    $response->assertJsonPath('data.buyouts_open', true);
});

test('renews the market at the next 20:00 in Madrid', function (): void {
    clockSeason(7);

    $this->getJson('/api/season')->assertJsonPath('data.next_market_renewal_at', '2026-09-28T20:00:00+02:00');

    $this->travelTo(CarbonImmutable::parse('2026-09-28 20:00:00', 'Europe/Madrid'));

    $this->getJson('/api/season')->assertJsonPath('data.next_market_renewal_at', '2026-09-29T20:00:00+02:00');
});

test('keeps the market renewal at 20:00 across the October clock change', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-24 21:00:00', 'Europe/Madrid'));
    clockSeason(7);

    $response = $this->getJson('/api/season');

    $response->assertOk();
    $response->assertJsonPath('data.next_market_renewal_at', '2026-10-25T20:00:00+01:00');
});
