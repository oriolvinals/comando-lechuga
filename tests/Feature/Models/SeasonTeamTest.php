<?php

declare(strict_types=1);

use App\Models\SeasonTeam;

test('casts its standing metrics', function (): void {
    $seasonTeam = SeasonTeam::factory()->create([
        'total_points' => 61,
        'live_points' => 14,
        'position' => 1,
        'last_position' => 2,
    ]);

    expect($seasonTeam->total_points)->toBe(61)
        ->and($seasonTeam->live_points)->toBe(14)
        ->and($seasonTeam->position)->toBe(1)
        ->and($seasonTeam->last_position)->toBe(2);
});

test('serializes the logo as a full asset URL', function (): void {
    $seasonTeam = SeasonTeam::factory()->create(['logo' => 'images/teams/123.png']);

    expect($seasonTeam->toArray()['logo'])->toBe(asset('images/teams/123.png'));
});

test('serializes an empty logo as an empty string', function (): void {
    $seasonTeam = SeasonTeam::factory()->create(['logo' => '']);

    expect($seasonTeam->toArray()['logo'])->toBe('');
});
