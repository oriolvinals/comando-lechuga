<?php

use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Team;

test('belongs to a player, a fixture, and a team, and stores the score', function (): void {
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['week_number' => 2]);
    $team = Team::factory()->create();
    $score = PlayerScore::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'team_id' => $team->id,
        'points' => 14,
        'stats' => ['goals' => [1, 5]],
        'ideal_formation' => true,
    ]);

    expect($score->player)->toBeInstanceOf(Player::class)
        ->and($score->fixture)->toBeInstanceOf(Fixture::class)
        ->and($score->fixture->week_number)->toBe(2)
        ->and($score->team)->toBeInstanceOf(Team::class)
        ->and($score->points)->toBe(14)
        ->and($score->stats)->toBe(['goals' => [1, 5]])
        ->and($score->ideal_formation)->toBeTrue()
        ->and($player->scores)->toHaveCount(1);
});
