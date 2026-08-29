<?php

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;

test('defaults to the scheduled state', function (): void {
    expect((new Fixture)->state)->toBe(FixtureState::Scheduled);
});

test('casts its state and date', function (): void {
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

test('casts and fills its formation columns', function (): void {
    $fixture = Fixture::factory()->create([
        'season_id' => Season::factory(),
        'team_local_id' => Team::factory(),
        'team_guest_id' => Team::factory(),
        'local_formation' => '4-3-3',
        'guest_formation' => '3-5-2',
    ]);

    expect($fixture->local_formation)->toBe('4-3-3')
        ->and($fixture->guest_formation)->toBe('3-5-2');
});

test('has many fixture lineups and fixture events', function (): void {
    $fixture = Fixture::factory()->create([
        'season_id' => Season::factory(),
        'team_local_id' => Team::factory(),
        'team_guest_id' => Team::factory(),
    ]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => Player::factory(), 'team_id' => Team::factory()]);
    FixtureEvent::factory()->create(['fixture_id' => $fixture->id, 'team_id' => Team::factory()]);

    expect($fixture->fixtureLineups)->toHaveCount(1)
        ->and($fixture->fixtureEvents)->toHaveCount(1);
});
