<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\SeasonManager;
use App\Models\SeasonManagerPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonManagerPlayer>
 */
class SeasonManagerPlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_manager_id' => SeasonManager::factory(),
            'player_id' => Player::factory(),
            'buyout_clause' => $this->faker->numberBetween(1000000, 50000000),
            'buyout_clause_locked_until' => now()->addWeek(),
            'shielded' => false,
            'shielded_until' => null,
        ];
    }
}
