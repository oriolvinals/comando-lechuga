<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\PlayerScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerScore>
 */
class PlayerScoreFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'points' => $this->faker->numberBetween(-10, 30),
            'week_number' => 1,
            'stats' => [],
            'ideal_formation' => false,
        ];
    }
}
