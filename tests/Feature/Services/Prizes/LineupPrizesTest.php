<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\BestNight;
use App\Services\Prizes\PrizeRanking;
use App\Services\Prizes\PrizeRow;
use App\Services\Prizes\SundayKing;
use App\Services\Prizes\WorstNight;
use App\Services\Prizes\WorstWeeks;

/**
 * A season on jornada 3 (in play): jornadas 1 and 2 are finished.
 *
 * @return array{Season, SeasonManager, SeasonManager, SeasonManager}
 */
function lineupPrizeSeason(): array
{
    $season = Season::factory()->create(['current_week' => 3, 'total_weeks' => 38]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'state' => FixtureState::FirstHalf]);
    $a = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);
    $b = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 2]);
    $late = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 3]);

    foreach ([[$a, 1, 60], [$b, 1, 40], [$a, 2, 50], [$b, 2, 50], [$late, 2, 30], [$a, 3, 99], [$b, 3, 1]] as [$manager, $week, $points]) {
        ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => $week, 'points' => $points]);
    }

    return [$season, $a, $b, $late];
}

/**
 * @param  list<PrizeRow>  $rows
 * @return array<int, PrizeRow>
 */
function byManager(array $rows): array
{
    return collect($rows)->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId)->all();
}

test('best night keeps the best finished jornada and ignores the one in play', function (): void {
    [$season, $a, $b, $late] = lineupPrizeSeason();

    $rows = byManager(app(BestNight::class)->rows($season));

    expect($rows[$a->id]->value)->toBe(60)
        ->and($rows[$a->id]->context)->toBe(['week_number' => 1])
        ->and($rows[$b->id]->value)->toBe(50)
        ->and($rows[$late->id]->value)->toBe(30);
});

test('a jornada tied on top counts for every tied manager', function (): void {
    [$season, $a, $b] = lineupPrizeSeason();

    $rows = byManager(app(SundayKing::class)->rows($season));

    expect($rows[$a->id]->value)->toBe(2)
        ->and($rows[$a->id]->context)->toBe(['weeks' => [1, 2]])
        ->and($rows[$b->id]->value)->toBe(1)
        ->and($rows[$b->id]->context)->toBe(['weeks' => [2]]);
});

test('a manager without a lineup in a jornada is never last in it', function (): void {
    [$season, $a, $b, $late] = lineupPrizeSeason();

    $rows = byManager(app(WorstWeeks::class)->rows($season));

    expect($rows[$b->id]->value)->toBe(1)
        ->and($rows[$b->id]->context)->toBe(['weeks' => [1]])
        ->and($rows[$late->id]->value)->toBe(1)
        ->and($rows[$late->id]->context)->toBe(['weeks' => [2]])
        ->and($rows[$a->id]->value)->toBe(0);
});

test('worst night keeps the lowest finished jornada and ignores the one in play', function (): void {
    [$season, $a, $b, $late] = lineupPrizeSeason();

    $rows = byManager(app(WorstNight::class)->rows($season));

    expect($rows[$a->id]->value)->toBe(50)
        ->and($rows[$a->id]->context)->toBe(['week_number' => 2])
        ->and($rows[$b->id]->value)->toBe(40)
        ->and($rows[$b->id]->context)->toBe(['week_number' => 1])
        ->and($rows[$late->id]->value)->toBe(30);
});

test('a jornada without a lineup does not count as a worst night', function (): void {
    [$season, , , $late] = lineupPrizeSeason();

    $rows = byManager(app(WorstNight::class)->rows($season));

    expect($rows[$late->id]->context)->toBe(['week_number' => 2]);
});

test('a manager without any finished lineup has no worst night', function (): void {
    [$season] = lineupPrizeSeason();
    $new = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 4]);

    $rows = byManager(app(WorstNight::class)->rows($season));

    expect($rows[$new->id]->value)->toBeNull();
});

test('worst night ranks the lowest first and ties share the lead', function (): void {
    $season = Season::factory()->create(['current_week' => 2, 'total_weeks' => 38]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::FirstHalf]);
    [$a, $b, $c] = collect([1, 2, 3])->map(fn (int $position): SeasonManager => SeasonManager::factory()->create(['season_id' => $season->id, 'position' => $position]))->all();

    foreach ([[$a, 20], [$b, 20], [$c, 35]] as [$manager, $points]) {
        ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => $points]);
    }

    $ranked = PrizeRanking::rank(app(WorstNight::class)->rows($season), [$a->id => 1, $b->id => 2, $c->id => 3], true);

    expect(array_column($ranked, 'place'))->toBe([1, 1, 3])
        ->and(PrizeRanking::leaders($ranked, true))->toBe([$a->id, $b->id]);
});
