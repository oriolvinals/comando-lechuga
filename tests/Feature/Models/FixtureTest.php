<?php

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;

test('defaults to the scheduled state', function () {
    expect((new Fixture)->state)->toBe(FixtureState::Scheduled);
});

test('casts its state and date', function () {
    $fixture = Fixture::factory()->create([
        'season_id' => Season::factory(),
        'team_local_id' => Team::factory(),
        'team_guest_id' => Team::factory(),
        'state' => FixtureState::Finished,
    ]);

    expect($fixture->state)->toBe(FixtureState::Finished)
        ->and($fixture->date)->toBeInstanceOf(CarbonImmutable::class);
});

test('maps Liga Fantasy state IDs to fixture states', function (int $stateId, FixtureState $state): void {
    expect(FixtureState::fromFantasyId($stateId))->toBe($state);
})->with([
    [1, FixtureState::Scheduled],
    [7, FixtureState::Finished],
]);
