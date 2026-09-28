<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ManagerShields;

function payWeeklyPrize(Season $season, SeasonManager $manager, int $weekNumber, string $paidAt): void
{
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::WeeklyPrize,
        'source_season_manager_id' => $manager->id,
        'player_id' => null,
        'week_number' => $weekNumber,
        'occurred_at' => $paidAt,
    ]);
}

function shieldAt(Season $season, SeasonManager $manager, string $occurredAt): void
{
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Shield,
        'source_season_manager_id' => $manager->id,
        'amount' => null,
        'occurred_at' => $occurredAt,
    ]);
}

test('a shield counts toward the first jornada whose prize was paid at or after it', function (): void {
    $season = Season::factory()->create(['total_weeks' => 38]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $rival = SeasonManager::factory()->create(['season_id' => $season->id]);

    payWeeklyPrize($season, $manager, 1, '2026-08-25 02:35:00');
    payWeeklyPrize($season, $rival, 1, '2026-08-25 02:30:00');
    payWeeklyPrize($season, $manager, 2, '2026-09-01 02:30:00');

    shieldAt($season, $manager, '2026-08-20 19:00:00');
    shieldAt($season, $manager, '2026-08-25 02:30:00');
    shieldAt($season, $manager, '2026-08-25 02:31:00');
    shieldAt($season, $manager, '2026-09-01 02:30:00');

    $ledger = app(ManagerShields::class)->forSeason($season);

    expect($ledger['current_week'])->toBe(3)
        ->and($ledger['used'][$manager->id])->toEqual([1 => 2, 2 => 2]);
});

test('a shield just after a prize payout counts toward the next jornada', function (): void {
    $season = Season::factory()->create(['total_weeks' => 38]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);

    payWeeklyPrize($season, $manager, 6, '2026-09-18 02:35:00');
    payWeeklyPrize($season, $manager, 7, '2026-09-21 02:29:00');

    shieldAt($season, $manager, '2026-09-18 19:10:00');
    shieldAt($season, $manager, '2026-09-21 02:30:00');

    $ledger = app(ManagerShields::class)->forSeason($season);

    expect($ledger['current_week'])->toBe(8)
        ->and($ledger['used'][$manager->id])->toEqual([7 => 1, 8 => 1])
        ->and(ManagerShields::current($ledger, $manager->id))
        ->toBe(['week_number' => 8, 'used' => 1, 'remaining' => 1, 'total' => 2]);
});

test('a jornada with a match still pending is closed once its prize is paid', function (): void {
    $season = Season::factory()->create(['total_weeks' => 38]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);

    payWeeklyPrize($season, $manager, 6, '2026-09-18 02:35:00');
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 6,
        'state' => FixtureState::Postponed,
        'date' => '2026-10-21 19:00:00',
    ]);

    shieldAt($season, $manager, '2026-09-19 12:00:00');

    $ledger = app(ManagerShields::class)->forSeason($season);

    expect($ledger['current_week'])->toBe(7)
        ->and($ledger['used'][$manager->id])->toBe([7 => 1]);
});

test('before any prize payout every shield counts toward jornada 1', function (): void {
    $season = Season::factory()->create(['total_weeks' => 38]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);

    shieldAt($season, $manager, '2026-08-15 10:00:00');

    $ledger = app(ManagerShields::class)->forSeason($season);

    expect($ledger['current_week'])->toBe(1)
        ->and(ManagerShields::current($ledger, $manager->id))
        ->toBe(['week_number' => 1, 'used' => 1, 'remaining' => 1, 'total' => 2]);
});

test('the current shield jornada never goes past the last jornada', function (): void {
    $season = Season::factory()->create(['total_weeks' => 2]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);

    payWeeklyPrize($season, $manager, 1, '2026-08-25 02:30:00');
    payWeeklyPrize($season, $manager, 2, '2026-09-01 02:30:00');

    expect(app(ManagerShields::class)->forSeason($season)['current_week'])->toBe(2);
});

test('remaining shields never go below zero', function (): void {
    $season = Season::factory()->create(['total_weeks' => 38]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);

    shieldAt($season, $manager, '2026-08-15 10:00:00');
    shieldAt($season, $manager, '2026-08-15 11:00:00');
    shieldAt($season, $manager, '2026-08-15 12:00:00');

    $ledger = app(ManagerShields::class)->forSeason($season);

    expect(ManagerShields::current($ledger, $manager->id))
        ->toBe(['week_number' => 1, 'used' => 3, 'remaining' => 0, 'total' => 2]);
});

test('a manager without shields has both left, in every jornada up to the current one', function (): void {
    $season = Season::factory()->create(['total_weeks' => 38]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $rival = SeasonManager::factory()->create(['season_id' => $season->id]);

    payWeeklyPrize($season, $manager, 1, '2026-08-25 02:30:00');
    payWeeklyPrize($season, $manager, 2, '2026-09-01 02:30:00');
    shieldAt($season, $rival, '2026-08-20 19:00:00');

    $ledger = app(ManagerShields::class)->forSeason($season);

    expect(ManagerShields::current($ledger, $manager->id))
        ->toBe(['week_number' => 3, 'used' => 0, 'remaining' => 2, 'total' => 2])
        ->and(ManagerShields::usedByWeek($ledger, $manager->id))->toBe([1 => 0, 2 => 0, 3 => 0])
        ->and(ManagerShields::usedByWeek($ledger, $rival->id))->toBe([1 => 1, 2 => 0, 3 => 0])
        ->and(ManagerShields::inWeek($ledger, $rival->id, 1))
        ->toBe(['week_number' => 1, 'used' => 1, 'remaining' => 1, 'total' => 2])
        ->and(ManagerShields::inWeek($ledger, $rival->id, 4))->toBeNull();
});

test('ignores shields and prizes from other seasons', function (): void {
    $season = Season::factory()->create(['total_weeks' => 38]);
    $otherSeason = Season::factory()->create(['total_weeks' => 38]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $otherSeason->id]);

    payWeeklyPrize($otherSeason, $otherManager, 5, '2026-08-25 02:30:00');
    shieldAt($otherSeason, $manager, '2026-08-20 19:00:00');

    $ledger = app(ManagerShields::class)->forSeason($season);

    expect($ledger)->toBe(['current_week' => 1, 'used' => []]);
});
