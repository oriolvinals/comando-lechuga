<?php

use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

test('wipes every table except seasons and season_managers', function (): void {
    $season = Season::factory()->create();
    SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create();
    $season->teams()->attach($team->id);
    Activity::factory()->create();
    Player::factory()->create();
    Fixture::factory()->create(['season_id' => $season->id]);
    FixtureEvent::factory()->create();
    FixtureLineup::factory()->create();
    ManagerLineup::factory()->create();
    ManagerLineupPlayer::factory()->create();
    ManagerPlayer::factory()->create();
    MarketPlayer::factory()->create();
    PlayerMarket::factory()->create();
    PlayerScore::factory()->create();
    PlayerSeason::factory()->create();

    // Compare counts before/after rather than asserting an absolute number:
    // SeasonManagerFactory's own `'season_id' => Season::factory()` default
    // (and PlayerFactory's own PlayerSeason-creation, and PlayerSeasonFactory's
    // own nested Season/Player defaults) create extra rows even when overridden
    // — the exact count is incidental, what matters is that the wipe leaves
    // seasons/season_managers unchanged while emptying everything else.
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
        ->and(Activity::query()->count())->toBe(0)
        ->and(Fixture::query()->count())->toBe(0)
        ->and(FixtureEvent::query()->count())->toBe(0)
        ->and(FixtureLineup::query()->count())->toBe(0)
        ->and(ManagerLineup::query()->count())->toBe(0)
        ->and(ManagerLineupPlayer::query()->count())->toBe(0)
        ->and(ManagerPlayer::query()->count())->toBe(0)
        ->and(MarketPlayer::query()->count())->toBe(0)
        ->and(Player::query()->count())->toBe(0)
        ->and(PlayerMarket::query()->count())->toBe(0)
        ->and(PlayerScore::query()->count())->toBe(0)
        ->and(PlayerSeason::query()->count())->toBe(0)
        ->and(Team::query()->count())->toBe(0)
        ->and(DB::table('season_team')->count())->toBe(0);
});
