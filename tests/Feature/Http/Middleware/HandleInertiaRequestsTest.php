<?php

declare(strict_types=1);

use App\Models\Season;
use Inertia\Testing\AssertableInertia as Assert;

test('shares the current season with every inertia page', function (): void {
    $season = Season::factory()->create([
        'name' => '2026/2027',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('season.id', $season->id)
        ->where('season.name', '2026/2027'));
});
