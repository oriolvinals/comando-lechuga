<?php

use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;

test('wipes every table except seasons and season_managers', function (): void {
    $season = Season::factory()->create();
    SeasonManager::factory()->create(['season_id' => $season->id]);
    Team::factory()->create();
    Player::factory()->create();
    Fixture::factory()->create(['season_id' => $season->id]);

    // Compare counts before/after rather than asserting an absolute number:
    // SeasonManagerFactory's own `'season_id' => Season::factory()` default
    // creates an extra Season row even though it's overridden above (Laravel
    // resolves nested factory relationships before applying overrides) — the
    // exact count is incidental, what matters is that the wipe leaves it
    // unchanged while emptying everything else.
    $seasonCountBefore = Season::query()->count();
    $seasonManagerCountBefore = SeasonManager::query()->count();

    // RefreshDatabase runs the full migration set once, up front, on an
    // empty database — so by the time this test runs, this migration is
    // already marked "ran" and `artisan migrate` would just skip it as
    // nothing-pending. Invoke `up()` directly instead, which is what
    // actually exercises the wipe logic against this test's seeded data.
    $migration = require database_path('migrations/2026_08_30_100000_wipe_data_for_worldcup26_primary_source.php');
    $migration->up();

    expect(Season::query()->count())->toBe($seasonCountBefore)
        ->and(SeasonManager::query()->count())->toBe($seasonManagerCountBefore)
        ->and(Team::query()->count())->toBe(0)
        ->and(Player::query()->count())->toBe(0)
        ->and(Fixture::query()->count())->toBe(0);
});
