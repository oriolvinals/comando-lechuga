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

        Artisan::call('season:sync-week');
        Artisan::call('season:sync-teams');
        Artisan::call('season:sync-fixtures');
        Artisan::call('season:sync-players');
        Artisan::call('season:sync-player-markets');
        Artisan::call('season:sync-player-scores');
        Artisan::call('season:sync-market');
        Artisan::call('season:sync-standing');
    }
}
