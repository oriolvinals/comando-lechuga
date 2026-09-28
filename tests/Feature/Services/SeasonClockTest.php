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
