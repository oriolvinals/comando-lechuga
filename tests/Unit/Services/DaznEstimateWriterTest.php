<?php

declare(strict_types=1);

use App\Models\FixtureLineup;
use App\Services\DaznEstimateWriter;
use Illuminate\Support\Collection;

test('has no official rating when every lineup is missing marca_points', function (): void {
    $lineups = new Collection([
        new FixtureLineup(['fantasy_stats' => null]),
        new FixtureLineup(['fantasy_stats' => ['mins_played' => [90, 2]]]),
    ]);

    expect(DaznEstimateWriter::hasOfficialRating($lineups))->toBeFalse();
});

test('has no official rating when marca_points is present but not positive', function (): void {
    $lineups = new Collection([
        new FixtureLineup(['fantasy_stats' => ['marca_points' => [-1, 0]]]),
    ]);

    expect(DaznEstimateWriter::hasOfficialRating($lineups))->toBeFalse();
});

test('has an official rating once any lineup carries a positive marca_points', function (): void {
    $lineups = new Collection([
        new FixtureLineup(['fantasy_stats' => null]),
        new FixtureLineup(['fantasy_stats' => ['marca_points' => [-1, 3]]]),
    ]);

    expect(DaznEstimateWriter::hasOfficialRating($lineups))->toBeTrue();
});
