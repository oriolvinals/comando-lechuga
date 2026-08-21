<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamLineupPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonTeamLineupPlayer>
 */
class SeasonTeamLineupPlayerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_team_lineup_id' => SeasonTeamLineup::factory(),
            'player_id' => Player::factory(),
            'points' => 0,
            'position' => $this->faker->randomElement(PlayerPosition::cases()),
        ];
    }
}
