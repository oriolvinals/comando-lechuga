<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureLineupProbability>
 */
class FixtureLineupProbabilityFactory extends Factory
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
