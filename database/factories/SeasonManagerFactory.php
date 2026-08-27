<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonManager>
 */
class SeasonManagerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999999),
            'fantasy_user_id' => $this->faker->unique()->numberBetween(1, 99999999),
            'name' => $this->faker->unique()->city().' FC',
            'logo' => $this->faker->imageUrl(),
            'total_points' => $this->faker->numberBetween(0, 1000),
            'live_points' => null,
            'position' => $this->faker->numberBetween(1, 20),
            'last_position' => $this->faker->numberBetween(1, 20),
            'value' => $this->faker->numberBetween(0, 500000000),
            'season_id' => Season::factory(),
        ];
    }
}
