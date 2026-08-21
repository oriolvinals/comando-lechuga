<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fixture>
 */
class FixtureFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fantasy_id' => $this->faker->unique()->numberBetween(1, 99999),
            'season_id' => Season::factory(),
            'week_number' => function (array $attributes): int {
                $season = Season::query()->findOrFail((int) $attributes['season_id']);

                return $this->faker->numberBetween(1, $season->total_weeks);
            },
            'date' => $this->faker->dateTimeBetween('now', '+1 year'),
            'team_local_id' => Team::factory(),
            'team_guest_id' => Team::factory(),
            'local_score' => null,
            'guest_score' => null,
            'state' => FixtureState::Scheduled,
        ];
    }
}
