<?php

use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\SeasonManager;

test('belongs to a season manager and has lineup players', function (): void {
    $seasonManager = SeasonManager::factory()->create();
    $lineup = ManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'tactical_formation' => [3, 5, 2],
        'week_number' => 2,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
    ]);

    expect($lineup->seasonManager)->toBeInstanceOf(SeasonManager::class)
        ->and($lineup->tactical_formation)->toBe([3, 5, 2])
        ->and($lineup->players)->toHaveCount(1)
        ->and($seasonManager->lineups)->toHaveCount(1);
});
