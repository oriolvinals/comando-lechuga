<?php

use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamLineupPlayer;

test('belongs to a season team and has lineup players', function () {
    $seasonTeam = SeasonTeam::factory()->create();
    $lineup = SeasonTeamLineup::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'tactical_formation' => [3, 5, 2],
        'week_number' => 2,
    ]);
    SeasonTeamLineupPlayer::factory()->create([
        'season_team_lineup_id' => $lineup->id,
    ]);

    expect($lineup->seasonTeam)->toBeInstanceOf(SeasonTeam::class)
        ->and($lineup->tactical_formation)->toBe([3, 5, 2])
        ->and($lineup->players)->toHaveCount(1)
        ->and($seasonTeam->lineups)->toHaveCount(1);
});
