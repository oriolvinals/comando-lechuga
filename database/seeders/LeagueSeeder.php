<?php

namespace Database\Seeders;

use App\Models\League;
use Illuminate\Database\Seeder;

class LeagueSeeder extends Seeder
{
    public function run(): void
    {
        League::factory()
            ->create([
                'name' => '2026/27',
                'current' => true,
            ]);
    }
}
