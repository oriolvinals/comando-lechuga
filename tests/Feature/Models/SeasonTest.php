<?php

use App\Models\Season;

test('resolves the season that includes today', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'total_weeks' => 38,
    ]);

    expect(Season::current())->toBeInstanceOf(Season::class)
        ->and(Season::current()->id)->toBe($season->id)
        ->and($season->total_weeks)->toBe(38);
});

test('has a nullable match_data_season_slug backfilled for existing seasons', function (): void {
    $season = Season::factory()->create();

    expect($season->match_data_season_slug)->toBe('2026-27-laliga');
});

test('match_data_season_slug can be null for a season created after the backfill', function (): void {
    $season = Season::factory()->create(['match_data_season_slug' => null]);

    expect($season->match_data_season_slug)->toBeNull();
});
