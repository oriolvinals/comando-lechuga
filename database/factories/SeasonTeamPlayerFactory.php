<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamPlayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonTeamPlayer>
 */
class SeasonTeamPlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_team_id' => SeasonTeam::factory(),
            'player_id' => Player::factory(),
            'buyout_clause' => $this->faker->numberBetween(1000000, 50000000),
            'buyout_clause_locked_until' => now()->addWeek(),
            'shielded' => false,
        ];
    }
}
