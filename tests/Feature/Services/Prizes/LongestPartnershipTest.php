<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\LongestPartnership;
use App\Services\Prizes\PrizeRow;

test('keeps the longest run of consecutive finished jornadas, the latest on a tie, and ignores the one in play', function (): void {
    $season = Season::factory()->create(['current_week' => 7, 'total_weeks' => 38]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 7, 'state' => FixtureState::FirstHalf]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $remiro = Player::factory()->create();
    $sivera = Player::factory()->create();

    $weeksByPlayer = [$remiro->id => [1, 2, 4, 5, 6, 7], $sivera->id => [1, 2, 3]];

    foreach (range(1, 7) as $week) {
        $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => $week]);

        foreach ($weeksByPlayer as $playerId => $weeks) {
            if (in_array($week, $weeks, true)) {
                ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $playerId]);
            }
        }
    }

    $row = collect(app(LongestPartnership::class)->rows($season))->first(fn (PrizeRow $row): bool => $row->seasonManagerId === $manager->id);

    expect($row->value)->toBe(3)
        ->and($row->context)->toBe(['player_id' => $remiro->id, 'from_week' => 4, 'to_week' => 6, 'alive' => true]);
});
