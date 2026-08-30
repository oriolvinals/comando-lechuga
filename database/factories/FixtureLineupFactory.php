<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureLineup>
 */
class FixtureLineupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fixture_id' => Fixture::factory(),
            'player_id' => Player::factory(),
            'unresolved_name' => null,
            'team_id' => Team::factory(),
            'starter' => true,
            'position' => 'Goalkeeper',
            'jersey' => (string) $this->faker->numberBetween(1, 25),
            'subbed_in' => false,
            'subbed_out' => false,
            'counterpart_player_id' => null,
            'sub_minute' => null,
            'stats' => [],
            'fantasy_points' => null,
            'fantasy_stats' => null,
        ];
    }
}
