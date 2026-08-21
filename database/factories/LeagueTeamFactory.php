<?php

namespace Database\Factories;

use App\Models\League;
use App\Models\LeagueTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeagueTeam>
 */
class LeagueTeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->city().' FC',
            'logo' => $this->faker->imageUrl(),
            'league_id' => League::first(),
        ];
    }
}
