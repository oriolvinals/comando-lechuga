<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerPosition;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagerLineupPlayer>
 */
class ManagerLineupPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'manager_lineup_id' => ManagerLineup::factory(),
            'player_id' => Player::factory(),
            'fixture_id' => null,
            'position' => $this->faker->randomElement(PlayerPosition::cases()),
        ];
    }
}
