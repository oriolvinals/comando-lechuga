<?php

namespace Database\Factories;

use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonTeamLineup>
 */
class SeasonTeamLineupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_team_id' => SeasonTeam::factory(),
            'tactical_formation' => [4, 4, 2],
            'points' => 0,
            'week_number' => 1,
        ];
    }
}
