<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ManagerBalanceSnapshot;
use App\Models\SeasonManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagerBalanceSnapshot>
 */
class ManagerBalanceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_manager_id' => SeasonManager::factory(),
            'money' => $this->faker->numberBetween(0, 300000000),
            'captured_at' => now(),
        ];
    }
}
