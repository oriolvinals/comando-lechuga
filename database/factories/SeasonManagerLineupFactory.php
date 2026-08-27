<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SeasonManager;
use App\Models\SeasonManagerLineup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonManagerLineup>
 */
class SeasonManagerLineupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_manager_id' => SeasonManager::factory(),
            'tactical_formation' => [4, 4, 2],
            'points' => 0,
            'week_number' => 1,
        ];
    }
}
