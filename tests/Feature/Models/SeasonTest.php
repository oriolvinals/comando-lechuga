<?php

use App\Models\Season;

test('resolves the season that includes today', function () {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'total_fixtures' => 380,
    ]);

    expect(Season::current())->toBeInstanceOf(Season::class)
        ->and(Season::current()->id)->toBe($season->id)
        ->and($season->total_fixtures)->toBe(380);
});
