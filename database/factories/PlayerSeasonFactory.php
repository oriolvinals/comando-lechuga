<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerSeason>
 */
class PlayerSeasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'season_id' => Season::factory(),
            'position' => $this->faker->randomElement(PlayerPosition::cases()),
            'market_value' => $this->faker->numberBetween(0, 200000000),
            'market_value_difference' => $this->faker->numberBetween(-500000, 500000),
            'points' => $this->faker->numberBetween(0, 1000),
            'average_points' => $this->faker->randomFloat(2, 0, 100),
        ];
    }
}
