<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PublishedStoryType;
use App\Models\PublishedStory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PublishedStory>
 */
class PublishedStoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => now('Europe/Madrid')->toDateString(),
            'type' => PublishedStoryType::MarketSignings,
            'batch' => 1,
            'part' => 1,
            'parts' => 1,
            'signings_count' => 0,
            'activity_ids' => [],
            'media_id' => (string) $this->faker->unique()->numberBetween(1, 999999999),
            'published_at' => now(),
        ];
    }
}
