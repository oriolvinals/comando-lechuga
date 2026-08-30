<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([SeasonSeeder::class]);

        // Dependency order — do not reorder: teams before fixtures/players (both need
        // season_team populated); fixtures+players before link-match-data-fixtures (needs
        // Fixture rows and Team.match_data_id); link-match-data-fixtures before
        // link-match-data-players (the latter reads already-linked fixtures' rosters);
        // link-match-data-players before the match-data backfill (so already-played
        // fixtures resolve as many lineup entries as possible on the very first pass).
        // The remaining sync commands (photos, markets, manager-players, manager-lineups,
        // market, standing, activity, week) are independent leaves with no ordering
        // requirement — they run fine on their normal schedule.
        Artisan::call('season:sync-teams');
        Artisan::call('season:sync-fixtures');
        Artisan::call('season:sync-players');
        Artisan::call('season:link-match-data-fixtures');
        Artisan::call('season:link-match-data-players');
        Artisan::call('season:sync-match-data-backfill');
    }
}
