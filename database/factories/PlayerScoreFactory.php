<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Team;
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
            'fixture_id' => Fixture::factory(),
            'team_id' => Team::factory(),
            'points' => $this->faker->numberBetween(-10, 30),
            'stats' => [],
            'ideal_formation' => false,
        ];
    }
}
