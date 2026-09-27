<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerStartProbability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerStartProbability>
 */
class PlayerStartProbabilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'fixture_id' => Fixture::factory(),
            'probability' => $this->faker->numberBetween(0, 100),
            'predicted_starter' => false,
            'confirmed_starter' => null,
            'fetched_at' => now(),
        ];
    }
}
