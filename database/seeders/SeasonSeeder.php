<?php

namespace Database\Seeders;

use App\Models\Season;
use Illuminate\Database\Seeder;

class SeasonSeeder extends Seeder
{
    public function run(): void
    {
        Season::factory()
            ->create([
                'name' => '2026/27',
                'current' => true,
            ]);
    }
}
