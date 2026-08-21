<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'main_name' => $this->faker->company(),
            'name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(),
            'short_name' => $this->faker->unique()->lexify('???'),
            'logo' => '',
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999),
        ];
    }
}
