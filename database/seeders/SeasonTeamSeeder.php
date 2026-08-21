<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SeasonTeam;
use Illuminate\Database\Seeder;

class SeasonTeamSeeder extends Seeder
{
    public function run(): void
    {
        SeasonTeam::factory()
            ->createMany([
                ['name' => 'DUBI FC', 'logo' => '', 'season_id' => 1],
            ]);

        SeasonTeam::factory(6)->create();
    }
}
