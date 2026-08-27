<?php

declare(strict_types=1);

use App\Models\SeasonManager;

test('casts its standing metrics', function (): void {
    $seasonManager = SeasonManager::factory()->create([
        'total_points' => 61,
        'live_points' => 14,
        'position' => 1,
        'last_position' => 2,
    ]);

    expect($seasonManager->total_points)->toBe(61)
        ->and($seasonManager->live_points)->toBe(14)
        ->and($seasonManager->position)->toBe(1)
        ->and($seasonManager->last_position)->toBe(2);
});

test('serializes the logo as a full asset URL', function (): void {
    $seasonManager = SeasonManager::factory()->create(['logo' => 'images/managers/123.png']);

    expect($seasonManager->toArray()['logo'])->toBe(asset('images/managers/123.png'));
});

test('serializes an empty logo as an empty string', function (): void {
    $seasonManager = SeasonManager::factory()->create(['logo' => '']);

    expect($seasonManager->toArray()['logo'])->toBe('');
});
