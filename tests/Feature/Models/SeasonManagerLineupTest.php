<?php

use App\Models\SeasonManager;
use App\Models\SeasonManagerLineup;
use App\Models\SeasonManagerLineupPlayer;

test('belongs to a season manager and has lineup players', function (): void {
    $seasonManager = SeasonManager::factory()->create();
    $lineup = SeasonManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'tactical_formation' => [3, 5, 2],
        'week_number' => 2,
    ]);
    SeasonManagerLineupPlayer::factory()->create([
        'season_manager_lineup_id' => $lineup->id,
    ]);

    expect($lineup->seasonManager)->toBeInstanceOf(SeasonManager::class)
        ->and($lineup->tactical_formation)->toBe([3, 5, 2])
        ->and($lineup->players)->toHaveCount(1)
        ->and($seasonManager->lineups)->toHaveCount(1);
});
