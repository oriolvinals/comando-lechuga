<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MarketPlayer;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketPlayer>
 */
class MarketPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999999),
            'expires_at' => $this->faker->dateTimeBetween('now', '+1 day'),
            'bids' => $this->faker->numberBetween(0, 10),
            'player_id' => Player::factory(),
            'sale_price' => $this->faker->numberBetween(0, 200000000),
            'value' => $this->faker->numberBetween(0, 200000000),
        ];
    }
}
