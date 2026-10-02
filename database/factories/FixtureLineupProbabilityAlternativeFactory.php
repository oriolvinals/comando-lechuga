<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FixtureLineupProbability;
use App\Models\FixtureLineupProbabilityAlternative;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureLineupProbabilityAlternative>
 */
class FixtureLineupProbabilityAlternativeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->lastName();

        return [
            'fixture_lineup_probability_id' => FixtureLineupProbability::factory(),
            'position' => 1,
            'player_id' => Player::factory(),
            'name' => $name,
            'futbolfantasy_slug' => mb_strtolower($name),
        ];
    }

    /**
     * FF's name for a player we couldn't link.
     */
    public function unlinked(): static
    {
        return $this->state(fn (): array => ['player_id' => null]);
    }
}
