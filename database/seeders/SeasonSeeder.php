<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Season;
use Illuminate\Database\Seeder;

class SeasonSeeder extends Seeder
{
    public function run(): void
    {
        Season::factory()
            ->create([
                'fantasy_id' => '017834818',
                'name' => '2026/27',
                'start_date' => '2026-06-29',
                'end_date' => '2027-08-10',
            ]);
    }
}
