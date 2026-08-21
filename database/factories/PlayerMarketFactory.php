<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\PlayerMarket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerMarket>
 */
class PlayerMarketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999),
            'player_id' => Player::factory(),
            'date' => $this->faker->date(),
            'value' => $this->faker->numberBetween(0, 200000000),
        ];
    }
}
