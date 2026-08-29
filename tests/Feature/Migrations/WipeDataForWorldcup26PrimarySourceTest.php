<?php

use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Illuminate\Support\Facades\Artisan;

test('wipes every table except seasons and season_managers', function (): void {
    $season = Season::factory()->create();
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    Team::factory()->create();
    Player::factory()->create();
    Fixture::factory()->create(['season_id' => $season->id]);

    Artisan::call('migrate', ['--path' => 'database/migrations/2026_08_30_100000_wipe_data_for_worldcup26_primary_source.php', '--force' => true]);

    expect(Season::query()->count())->toBe(1)
        ->and(SeasonManager::query()->count())->toBe(1)
        ->and(Team::query()->count())->toBe(0)
        ->and(Player::query()->count())->toBe(0)
        ->and(Fixture::query()->count())->toBe(0);
});
