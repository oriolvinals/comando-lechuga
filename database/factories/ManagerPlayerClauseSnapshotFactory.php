<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClauseSnapshotSource;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\SeasonManager;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManagerPlayerClauseSnapshot>
 */
class ManagerPlayerClauseSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'season_manager_id' => SeasonManager::factory(),
            'player_id' => Player::factory(),
            'buyout_clause' => $this->faker->numberBetween(1000000, 50000000),
            'buyout_clause_locked_until' => now()->subDay(),
            'market_value' => $this->faker->numberBetween(500000, 50000000),
            'captured_at' => now(),
            'source' => ClauseSnapshotSource::Sync,
            'raise_amount' => 0,
            'note' => '',
        ];
    }
}
