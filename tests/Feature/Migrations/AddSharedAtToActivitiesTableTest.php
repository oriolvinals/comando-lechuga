<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;

test('backfillSharedAt marks every existing signing as shared at its occurred_at and leaves other activities alone', function (): void {
    $signing = Activity::factory()->create(['type' => SeasonActivityType::Signing, 'occurred_at' => '2026-10-01 22:00:00']);
    $alreadyShared = Activity::factory()->create(['type' => SeasonActivityType::Signing, 'shared_at' => '2026-10-02 08:00:00']);
    $sale = Activity::factory()->create(['type' => SeasonActivityType::Sale]);

    // RefreshDatabase already ran this migration, so call its backfill directly (see AddInstagramUsernameToSeasonManagersTableTest).
    $migration = require database_path('migrations/2026_10_02_130000_add_shared_at_to_activities_table.php');
    $migration->backfillSharedAt();

    expect($signing->refresh()->shared_at?->toDateTimeString())->toBe('2026-10-01 22:00:00')
        ->and($alreadyShared->refresh()->shared_at?->toDateTimeString())->toBe('2026-10-02 08:00:00')
        ->and($sale->refresh()->shared_at)->toBeNull();
});
