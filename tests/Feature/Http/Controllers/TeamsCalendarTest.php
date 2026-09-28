<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

function calendarSeason(): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addYear(),
        'total_weeks' => 38,
    ]);
}

/**
 * Teams named so that, with no finished fixture, the table orders them as
 * given (all on 0 points, name ascending) — positions 1, 2, 3…
 *
 * @return list<Team>
 */
function calendarTeams(Season $season, int $count): array
{
    $teams = [];

    foreach (range(1, $count) as $index) {
        $teams[] = Team::factory()->create(['main_name' => sprintf('Team %02d', $index)]);
    }

    $season->teams()->attach(array_map(fn (Team $team): int => $team->id, $teams));

    return $teams;
}

function calendarFixture(Season $season, Team $local, Team $guest, int $week, int $daysAhead, FixtureState $state = FixtureState::Scheduled): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => $week,
        'team_local_id' => $local->id,
        'team_guest_id' => $guest->id,
        'date' => now()->addDays($daysAhead),
        'state' => $state,
    ]);
}

test('the standings tab is the default and does not send the calendar', function (): void {
    calendarSeason();

    $this->get(route('teams.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('teams/index')
            ->where('view', 'clasificacion')
            ->has('standings')
            ->missing('calendar'));
});

test('the calendario tab sends the calendar alongside the standings', function (): void {
    $season = calendarSeason();
    [$home, $away] = calendarTeams($season, 2);
    calendarFixture($season, $home, $away, 1, 1);

    $this->get(route('teams.index', ['vista' => 'calendario']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('view', 'calendario')
            ->has('standings', 2)
            ->has('calendar', 2));
});

test('lists each team\'s scheduled matches by date, with a rescheduled match in its date position', function (): void {
    $season = calendarSeason();
    [$team, $rivalA, $rivalB, $rivalC] = calendarTeams($season, 4);

    calendarFixture($season, $team, $rivalA, 7, 1);
    calendarFixture($season, $rivalB, $team, 8, 8);
    // J6, moved to after J8 — still listed by date, not by jornada.
    $moved = calendarFixture($season, $team, $rivalC, 6, 15);
    calendarFixture($season, $rivalA, $team, 9, 22);
    // Postponed (no date to play it yet) and already-finished matches stay out.
    calendarFixture($season, $team, $rivalB, 5, 3, FixtureState::Postponed);
    calendarFixture($season, $rivalC, $team, 4, -3, FixtureState::Finished);

    $this->get(route('teams.index', ['vista' => 'calendario']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('calendar.0', fn (Assert $row): Assert => $row
                ->where('team.id', $team->id)
                ->has('matches', 4)
                ->where('matches.0.week_number', 7)
                ->where('matches.0.opponent.id', $rivalA->id)
                ->where('matches.0.is_home', true)
                ->where('matches.0.rescheduled', false)
                ->where('matches.1.week_number', 8)
                ->where('matches.1.opponent.id', $rivalB->id)
                ->where('matches.1.is_home', false)
                ->where('matches.2.fixture_id', $moved->id)
                ->where('matches.2.week_number', 6)
                ->where('matches.2.rescheduled', true)
                ->where('matches.3.week_number', 9)
                ->where('matches.3.rescheduled', false)
                ->etc()));
});

test('caps each row at the next 10 matches and leaves shorter rows short', function (): void {
    $season = calendarSeason();
    [$busy, $rival, $idle] = calendarTeams($season, 3);

    foreach (range(1, 12) as $week) {
        calendarFixture($season, $busy, $rival, $week, $week);
    }

    $response = $this->get(route('teams.index', ['vista' => 'calendario']));

    $response->assertOk();
    $rows = collect($response->inertiaProps('calendar'))->keyBy('team.id');

    expect($rows[$busy->id]['matches'])->toHaveCount(10)
        ->and(array_column($rows[$busy->id]['matches'], 'week_number'))->toBe(range(1, 10))
        ->and($rows[$idle->id]['matches'])->toBe([])
        ->and($rows[$idle->id]['average'])->toBeNull();
});

test('rates rivals by current table position, averages them and sorts the easiest run first', function (): void {
    $season = calendarSeason();
    // Positions 1–4; difficulty (p − 2.5) / 1.5 → −1, −0.333, +0.333, +1.
    [$first, $second, $third, $fourth] = calendarTeams($season, 4);

    calendarFixture($season, $first, $fourth, 1, 1);
    calendarFixture($season, $second, $third, 1, 2);
    calendarFixture($season, $third, $first, 2, 8);

    $response = $this->get(route('teams.index', ['vista' => 'calendario']));

    $response->assertOk();
    $rows = $response->inertiaProps('calendar');

    // first: vs 4th (+1), vs 3rd (+0.333) → +0.667 · second: vs 3rd → +0.333
    // third: vs 2nd (−0.333), vs 1st (−1) → −0.667 · fourth: vs 1st → −1.
    expect(array_map(fn (array $row): int => $row['team']['id'], $rows))
        ->toBe([$first->id, $second->id, $third->id, $fourth->id])
        ->and(array_column($rows, 'average'))->toEqual([0.667, 0.333, -0.667, -1])
        ->and(array_column($rows, 'position'))->toBe([1, 2, 3, 4])
        ->and($rows[0]['matches'][0]['rival_position'])->toBe(4)
        ->and($rows[0]['matches'][0]['difficulty'])->toEqual(1)
        ->and($rows[2]['matches'][0]['is_home'])->toBeFalse()
        ->and($rows[2]['matches'][1]['is_home'])->toBeTrue();
});

test('builds the calendar in a constant number of queries', function (): void {
    $season = calendarSeason();

    $addTeamPair = function (int $week) use ($season): void {
        [$local, $guest] = [Team::factory()->create(), Team::factory()->create()];
        $season->teams()->attach([$local->id, $guest->id]);
        calendarFixture($season, $local, $guest, $week, $week);
        calendarFixture($season, $guest, $local, $week + 1, $week + 7);
    };

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('teams.index', ['vista' => 'calendario']))->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $addTeamPair(1);
    $withOnePair = $countQueries();

    foreach (range(2, 6) as $week) {
        $addTeamPair($week);
    }

    expect($countQueries())->toBe($withOnePair);
});
