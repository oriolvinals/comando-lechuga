<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\Player;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FixtureLineupProbability>
 */
class FixtureLineupProbabilityFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'player_id' => Player::factory(),
            'fixture_id' => Fixture::factory(),
            'probability' => $this->faker->numberBetween(0, 100),
            'predicted_starter' => false,
            'confirmed_starter' => null,
            'pitch_x' => null,
            'pitch_y' => null,
            'fetched_at' => now(),
        ];
    }

    /**
     * In FF's probable XI, drawn at this spot of its pitch (FF attacks up).
     */
    public function onPitch(int $pitchX, int $pitchY): static
    {
        return $this->state(fn (): array => [
            'predicted_starter' => true,
            'pitch_x' => $pitchX,
            'pitch_y' => $pitchY,
        ]);
    }
}
