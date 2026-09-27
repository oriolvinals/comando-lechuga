<?php

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use App\Services\LeagueStandings;
use Carbon\CarbonImmutable;

test('positions only count fixtures played up to the given date', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonth()]);
    [$early, $late, $idle] = Team::factory()->count(3)->create()->all();
    $season->teams()->attach([$early->id, $late->id, $idle->id]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => '2026-09-01 18:00:00',
        'team_local_id' => $early->id,
        'team_guest_id' => $idle->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => '2026-09-10 18:00:00',
        'team_local_id' => $late->id,
        'team_guest_id' => $idle->id,
        'local_score' => 5,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);

    $standings = app(LeagueStandings::class);

    expect($standings->positions($season, CarbonImmutable::parse('2026-09-05 23:59:59')))
        ->toMatchArray([$early->id => 1])
        ->and($standings->positions($season)[$late->id])->toBe(1)
        ->and($standings->positions($season))->toHaveCount(3);
});

test('rates a rival difficulty from -1 for the leader to +1 for the last team', function (int $position, int $teamCount, float $expected): void {
    expect(LeagueStandings::difficulty($position, $teamCount))->toBe($expected);
})->with([
    'leader of 20' => [1, 20, -1.0],
    'last of 20' => [20, 20, 1.0],
    'mid table of 3' => [2, 3, 0.0],
    'single-team table' => [1, 1, 0.0],
]);
