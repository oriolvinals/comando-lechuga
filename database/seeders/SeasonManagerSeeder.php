<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SeasonManager;
use Illuminate\Database\Seeder;

class SeasonManagerSeeder extends Seeder
{
    public function run(): void
    {
        SeasonManager::factory()
            ->createMany([
                ['name' => 'DUBI FC', 'logo' => '', 'season_id' => 1],
            ]);

        SeasonManager::factory(6)->create();
    }
}
