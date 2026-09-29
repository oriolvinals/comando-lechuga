<?php

declare(strict_types=1);

use App\Models\Fixture;
use App\Models\FixtureLineup;

test('backfillDaznPublished marks a fixture published once any lineup already has an official rating', function (): void {
    $publishedFixture = Fixture::factory()->create(['dazn_published' => false]);
    $unpublishedFixture = Fixture::factory()->create(['dazn_published' => false]);
    $noStatsFixture = Fixture::factory()->create(['dazn_published' => false]);

    FixtureLineup::factory()->create([
        'fixture_id' => $publishedFixture->id,
        'fantasy_stats' => ['marca_points' => [-1, 3]],
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $unpublishedFixture->id,
        'fantasy_stats' => ['marca_points' => [-1, 0]],
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $noStatsFixture->id,
        'fantasy_stats' => null,
    ]);

    // RefreshDatabase runs the full migration set once, up front, so by the
    // time this test runs, this migration is already marked "ran" and its
    // up() would fail trying to re-add the dazn_published column. Invoke the
    // extracted backfill method directly instead — it's the part this test
    // targets, and it takes no app classes (plain query builder + PHP), so
    // calling it here doesn't exercise anything different from a real deploy.
    $migration = require database_path('migrations/2026_09_29_120100_add_dazn_published_to_fixtures_table.php');
    $migration->backfillDaznPublished();

    expect($publishedFixture->refresh()->dazn_published)->toBeTrue()
        ->and($unpublishedFixture->refresh()->dazn_published)->toBeFalse()
        ->and($noStatsFixture->refresh()->dazn_published)->toBeFalse();
});
