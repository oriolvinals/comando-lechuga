<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Player;
use App\Models\Season;
use App\Models\ValueForecast;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValueForecast>
 */
class ValueForecastFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'player_id' => Player::factory(),
            'reference_date' => now()->toDateString(),
            'target_date' => now()->addDay()->toDateString(),
            'value' => 10_000_000,
            'predicted_value' => 10_100_000,
            'low' => 10_000_000,
            'high' => 10_200_000,
            'change_pct' => 1.0,
            'up_probability' => 0.9,
            'reasons' => [],
        ];
    }
}
