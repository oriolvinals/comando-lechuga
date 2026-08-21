<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SeasonActivityType;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonActivity>
 */
class SeasonActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999999),
            'type' => SeasonActivityType::Signing,
            'season_id' => Season::factory(),
            'source_season_team_id' => SeasonTeam::factory(),
            'target_season_team_id' => null,
            'player_id' => Player::factory(),
            'amount' => $this->faker->numberBetween(0, 50000000),
            'week_number' => null,
            'occurred_at' => now(),
        ];
    }
}
