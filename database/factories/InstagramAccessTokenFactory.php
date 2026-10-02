<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InstagramAccessToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstagramAccessToken>
 */
class InstagramAccessTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'access_token' => 'IG'.$this->faker->sha256(),
            'expires_at' => now()->addDays(60),
            'refreshed_at' => now(),
        ];
    }
}
