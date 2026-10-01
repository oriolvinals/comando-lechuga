<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Season;
use App\Models\ValueForecastFit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ValueForecastFit>
 */
class ValueForecastFitFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'reference_date' => now()->toDateString(),
            'inputs_hash' => '',
            'coefficients' => [],
            'quantiles' => [],
            'metrics' => [],
        ];
    }
}
