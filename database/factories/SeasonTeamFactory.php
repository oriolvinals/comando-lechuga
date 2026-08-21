<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonTeam>
 */
class SeasonTeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->city().' FC',
            'logo' => $this->faker->imageUrl(),
            'season_id' => Season::first(),
        ];
    }
}
