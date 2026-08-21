<?php

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Team;

test('casts its position and status enums', function () {
    $player = Player::factory()->create([
        'position' => PlayerPosition::Midfielder,
        'status' => PlayerStatus::Doubtful,
        'image' => 'images/player/1.png',
        'team_id' => Team::factory(),
    ]);

    expect($player->position)->toBe(PlayerPosition::Midfielder)
        ->and($player->status)->toBe(PlayerStatus::Doubtful)
        ->and($player->toArray()['image'])->toBe(asset('storage/images/player/1.png'));
});

test('maps Liga Fantasy position IDs to player positions', function (int $positionId, PlayerPosition $position): void {
    expect(PlayerPosition::fromFantasyId($positionId))->toBe($position);
})->with([
    [1, PlayerPosition::Goalkeeper],
    [2, PlayerPosition::Defender],
    [3, PlayerPosition::Midfielder],
    [4, PlayerPosition::Forward],
    [5, PlayerPosition::Coach],
]);
