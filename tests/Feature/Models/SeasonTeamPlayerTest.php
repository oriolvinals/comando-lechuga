<?php

use App\Models\Player;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamPlayer;

test('belongs to a season team and a player and stores the clause state', function () {
    $seasonTeam = SeasonTeam::factory()->create();
    $player = Player::factory()->create();
    $lockedUntil = now()->addWeek();

    $seasonTeamPlayer = SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $player->id,
        'buyout_clause' => 35273936,
        'buyout_clause_locked_until' => $lockedUntil,
        'shielded' => true,
    ]);

    expect($seasonTeamPlayer->seasonTeam->is($seasonTeam))->toBeTrue()
        ->and($seasonTeamPlayer->player->is($player))->toBeTrue()
        ->and($seasonTeamPlayer->buyout_clause)->toBe(35273936)
        ->and($seasonTeamPlayer->buyout_clause_locked_until->toDateTimeString())->toBe($lockedUntil->toDateTimeString())
        ->and($seasonTeamPlayer->shielded)->toBeTrue()
        ->and($seasonTeam->fresh())->not->toBeNull();
});
