<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999),
            'nickname' => $this->faker->name(),
            'status' => $this->faker->randomElement(PlayerStatus::cases()),
            'image' => '',
            'team_id' => Team::factory(),
        ];
    }
}
