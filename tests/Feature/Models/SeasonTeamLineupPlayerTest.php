<?php

use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamLineupPlayer;

test('belongs to a lineup and player', function (): void {
    $lineup = SeasonTeamLineup::factory()->create();
    $player = Player::factory()->create();
    $lineupPlayer = SeasonTeamLineupPlayer::factory()->create([
        'season_team_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'points' => 6,
        'position' => PlayerPosition::Goalkeeper,
    ]);

    expect($lineupPlayer->lineup)->toBeInstanceOf(SeasonTeamLineup::class)
        ->and($lineupPlayer->player)->toBeInstanceOf(Player::class)
        ->and($lineupPlayer->points)->toBe(6)
        ->and($lineupPlayer->position)->toBe(PlayerPosition::Goalkeeper)
        ->and($player->lineupPlayers)->toHaveCount(1);
});
