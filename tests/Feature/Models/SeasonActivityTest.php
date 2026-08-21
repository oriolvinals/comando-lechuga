<?php

use App\Enums\SeasonActivityType;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;

test('casts its type and belongs to a season, teams and a player', function (): void {
    $season = Season::factory()->create();
    $sourceSeasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $targetSeasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();

    $activity = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $sourceSeasonTeam->id,
        'target_season_team_id' => $targetSeasonTeam->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Buyout,
        'amount' => 9011684,
        'week_number' => null,
    ]);

    expect($activity->type)->toBe(SeasonActivityType::Buyout)
        ->and($activity->season->is($season))->toBeTrue()
        ->and($activity->sourceSeasonTeam->is($sourceSeasonTeam))->toBeTrue()
        ->and($activity->targetSeasonTeam->is($targetSeasonTeam))->toBeTrue()
        ->and($activity->player->is($player))->toBeTrue()
        ->and($activity->amount)->toBe(9011684)
        ->and($activity->week_number)->toBeNull();
});

test('allows a null target team, player and week number', function (): void {
    $activity = SeasonActivity::factory()->create([
        'target_season_team_id' => null,
        'player_id' => null,
        'week_number' => 1,
        'amount' => 2700000,
    ]);

    expect($activity->targetSeasonTeam)->toBeNull()
        ->and($activity->player)->toBeNull()
        ->and($activity->week_number)->toBe(1)
        ->and($activity->amount)->toBe(2700000);
});
