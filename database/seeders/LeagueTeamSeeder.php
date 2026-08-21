<?php

namespace Database\Seeders;

use App\Models\LeagueTeam;
use Illuminate\Database\Seeder;

class LeagueTeamSeeder extends Seeder
{
    public function run(): void
    {
        LeagueTeam::factory()
            ->createMany([
                ['name' => 'DUBI FC', 'logo' => '', 'league_id' => 1],
            ]);

        LeagueTeam::factory(6)->create();
    }
}
