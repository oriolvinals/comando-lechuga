<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerMarket;
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
 * A season team whose squad value on today's reference day is `$value` —
 * MatchDifficulty's `general` variant reduces to this z-scored value (plus
 * home/away) when no team has played a finished match yet, the same way
 * MatchDifficultyTest's own difficultyTeam() helper builds a world.
 */
function calendarTeamWithValue(Season $season, string $name, int $value): Team
{
    $team = Team::factory()->create(['main_name' => $name]);
    $season->teams()->attach($team->id);

    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->toDateString(), 'value' => $value]);

    return $team;
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

    // The team-strength difficulty (unlike the old table-position one) no
    // longer guarantees $team lands on row 0 — locate its row by id instead.
    $response = $this->get(route('teams.index', ['vista' => 'calendario']));
    $response->assertOk();
    $rowIndex = collect($response->inertiaProps('calendar'))->search(
        fn (array $row): bool => $row['team']['id'] === $team->id,
    );

    $response
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has("calendar.{$rowIndex}", fn (Assert $row): Assert => $row
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

test('rates each match by team strength, averages the row and sorts the easiest run first', function (): void {
    $season = calendarSeason();
    // Six teams — the same extreme-outlier shape MatchDifficultyTest uses to
    // reach the scale's exact 0/10 clamps: a giant and a minnow, plus four
    // equal mid teams that keep the population's mean and spread stable.
    $giant = calendarTeamWithValue($season, 'Giant', 4_000_000_000);
    $minnow = calendarTeamWithValue($season, 'Minnow', 1_000);
    $easyMid = calendarTeamWithValue($season, 'Mid Easy', 2_000_000);
    $hardMid = calendarTeamWithValue($season, 'Mid Hard', 2_000_000);
    calendarTeamWithValue($season, 'Mid C', 2_000_000);
    calendarTeamWithValue($season, 'Mid D', 2_000_000);

    // Mid Easy's only match: at home to the minnow — the easiest fixture the
    // scale allows, clamped to 0.
    calendarFixture($season, $easyMid, $minnow, 1, 1);
    // Mid Hard's only match: away at the giant — the hardest, clamped to 10.
    calendarFixture($season, $giant, $hardMid, 1, 2);

    $response = $this->get(route('teams.index', ['vista' => 'calendario']));

    $response->assertOk();
    $rows = collect($response->inertiaProps('calendar'))->keyBy('team.id');

    // json_decode turns a whole-number float (0.0, 10.0) back into an int —
    // cast before comparing, the same way the other consumer tests do.
    expect((float) $rows[$easyMid->id]['matches'][0]['difficulty'])->toBe(0.0)
        ->and($rows[$easyMid->id]['matches'][0]['difficulty_variant'])->toBe('general')
        ->and($rows[$easyMid->id]['matches'][0]['rival_position'])->toBeInt()
        ->and((float) $rows[$easyMid->id]['average'])->toBe(0.0)
        ->and((float) $rows[$hardMid->id]['matches'][0]['difficulty'])->toBe(10.0)
        ->and((float) $rows[$hardMid->id]['average'])->toBe(10.0);

    // The board sorts ascending (easiest run first): every non-null average
    // stays within 0–10, sorted low to high, with Mid Easy's 0 at the bottom
    // and Mid Hard's 10 at the top of the real (non-idle) rows.
    $averages = $rows->pluck('average')->filter(fn (int|float|null $average): bool => $average !== null)->values();
    expect($averages->all())->toBe($averages->sort()->values()->all())
        ->and((float) $averages->first())->toBe(0.0)
        ->and((float) $averages->last())->toBe(10.0);
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
        // MatchDifficulty (and TeamStrength) are bound scoped: without this,
        // their per-request memos would carry over into the second
        // measurement below (a test-only artifact — a real request always
        // gets a fresh instance) and hide any real N+1.
        app()->forgetScopedInstances();
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

    expect($withOnePair)->toBeLessThanOrEqual(40)
        ->and($countQueries())->toBe($withOnePair);
});
