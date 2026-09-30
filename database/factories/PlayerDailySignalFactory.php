<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerDailySignal;
use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerDailySignal>
 */
class PlayerDailySignalFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'player_id' => Player::factory(),
            'date' => now()->toDateString(),
            'status' => PlayerStatus::Ok,
            'next_fixture_id' => null,
            'start_probability' => null,
            'predicted_starter' => false,
            'confirmed_starter' => null,
            'next_difficulty' => null,
            'listed' => false,
        ];
    }
}
