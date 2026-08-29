<?php

use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\Player;
use App\Models\Team;

test('casts its minute and booleans, and allows a null player', function (): void {
    $event = FixtureEvent::factory()->create([
        'fixture_id' => Fixture::factory(),
        'team_id' => Team::factory(),
        'player_id' => null,
        'type' => 'yellow_card',
        'minute' => 45,
        'is_own_goal' => false,
        'is_penalty' => false,
    ]);

    expect($event->minute)->toBe(45)
        ->and($event->type)->toBe('yellow_card')
        ->and($event->player_id)->toBeNull();
});

test('belongs to a fixture, a team, and optionally a player', function (): void {
    $player = Player::factory()->create();
    $event = FixtureEvent::factory()->create([
        'fixture_id' => Fixture::factory(),
        'team_id' => Team::factory(),
        'player_id' => $player->id,
    ]);

    expect($event->fixture)->toBeInstanceOf(Fixture::class)
        ->and($event->team)->toBeInstanceOf(Team::class)
        ->and($event->player->id)->toBe($player->id);
});
