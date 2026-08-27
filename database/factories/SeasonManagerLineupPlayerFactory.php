<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\SeasonManagerLineup;
use App\Models\SeasonManagerLineupPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonManagerLineupPlayer>
 */
class SeasonManagerLineupPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_manager_lineup_id' => SeasonManagerLineup::factory(),
            'player_id' => Player::factory(),
            'points' => 0,
            'position' => $this->faker->randomElement(PlayerPosition::cases()),
        ];
    }
}
