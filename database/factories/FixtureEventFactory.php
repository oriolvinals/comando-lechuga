<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureEvent>
 */
class FixtureEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => Fixture::factory(),
            'team_id' => Team::factory(),
            'player_id' => Player::factory(),
            'type' => 'goal',
            'minute' => $this->faker->numberBetween(1, 90),
            'is_own_goal' => false,
            'is_penalty' => false,
        ];
    }
}
