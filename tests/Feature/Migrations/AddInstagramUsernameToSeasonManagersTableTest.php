<?php

declare(strict_types=1);

use App\Models\SeasonManager;

test('backfillInstagramUsernames fills every mapped manager across seasons and leaves the rest empty', function (): void {
    $mapped = SeasonManager::factory()->create(['fantasy_user_id' => 2890485]);
    $mappedInAnotherSeason = SeasonManager::factory()->create(['fantasy_user_id' => 2890485]);
    $unmapped = SeasonManager::factory()->create(['fantasy_user_id' => 999]);

    // RefreshDatabase already ran this migration, so call its backfill directly (see AddDaznPublishedToFixturesTableTest).
    $migration = require database_path('migrations/2026_10_02_120000_add_instagram_username_to_season_managers_table.php');
    $migration->backfillInstagramUsernames();

    expect($mapped->refresh()->instagram_username)->toBe('oriolvinals')
        ->and($mappedInAnotherSeason->refresh()->instagram_username)->toBe('oriolvinals')
        ->and($unmapped->refresh()->instagram_username)->toBe('');
});
