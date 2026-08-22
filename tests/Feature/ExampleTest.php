<?php

use App\Models\Season;

test('returns a successful response', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
});
