<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999),
            'position' => $this->faker->randomElement(PlayerPosition::cases()),
            'nickname' => $this->faker->name(),
            'status' => $this->faker->randomElement(PlayerStatus::cases()),
            'market_value' => $this->faker->numberBetween(0, 200000000),
            'points' => $this->faker->numberBetween(0, 1000),
            'average_points' => $this->faker->randomFloat(2, 0, 100),
            'image' => '',
            'team_id' => Team::factory(),
        ];
    }
}
