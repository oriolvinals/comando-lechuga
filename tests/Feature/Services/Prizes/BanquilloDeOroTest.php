<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\BanquilloDeOro;
use App\Services\Prizes\PrizeRow;

test('adds the points of squad players left out of each finished lineup', function (): void {
    $season = Season::factory()->create(['current_week' => 2, 'total_weeks' => 38]);
    $finished = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    $live = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::FirstHalf]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    [$starter, $benched, $alsoBenched] = Player::factory()->count(3)->create()->all();

    foreach ([$starter, $benched, $alsoBenched] as $player) {
        ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $player->id]);
    }

    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $starter->id]);

    FixtureLineup::factory()->create(['fixture_id' => $finished->id, 'player_id' => $starter->id, 'fantasy_points' => 20]);
    $topMiss = FixtureLineup::factory()->create(['fixture_id' => $finished->id, 'player_id' => $benched->id, 'fantasy_points' => 14]);
    FixtureLineup::factory()->create(['fixture_id' => $finished->id, 'player_id' => $alsoBenched->id, 'fantasy_points' => null]);
    FixtureLineup::factory()->create(['fixture_id' => $live->id, 'player_id' => $benched->id, 'fantasy_points' => 30]);

    $row = collect(app(BanquilloDeOro::class)->rows($season))->first(fn (PrizeRow $row): bool => $row->seasonManagerId === $manager->id);

    expect($row->value)->toBe(14)
        ->and($row->context)->toBe(['top_miss' => [
            'fixture_lineup_id' => $topMiss->id, 'fixture_id' => $finished->id, 'player_id' => $benched->id, 'week_number' => 1, 'points' => 14,
        ]]);
});

test('uses the squad at each lineup lock and skips jornadas without a lineup', function (): void {
    $season = Season::factory()->create(['current_week' => 3, 'total_weeks' => 38]);
    $first = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    $second = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => '2026-08-29 19:00:00', 'state' => FixtureState::Scheduled]);
    [$manager, $lateJoiner] = SeasonManager::factory()->count(2)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    [$soldBeforeWeekTwo, $signedForWeekTwo, $lateJoinersPlayer] = Player::factory()->count(3)->create()->all();
    ManagerPlayer::factory()->create(['season_manager_id' => $lateJoiner->id, 'player_id' => $lateJoinersPlayer->id]);

    Activity::factory()->create([
        'season_id' => $season->id, 'type' => SeasonActivityType::Sale, 'player_id' => $soldBeforeWeekTwo->id,
        'source_season_manager_id' => $manager->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-18 10:00:00',
    ]);
    Activity::factory()->create([
        'season_id' => $season->id, 'type' => SeasonActivityType::Signing, 'player_id' => $signedForWeekTwo->id,
        'source_season_manager_id' => $manager->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-19 20:00:00',
    ]);

    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1]);
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2]);

    FixtureLineup::factory()->create(['fixture_id' => $first->id, 'player_id' => $soldBeforeWeekTwo->id, 'fantasy_points' => 5]);
    FixtureLineup::factory()->create(['fixture_id' => $first->id, 'player_id' => $signedForWeekTwo->id, 'fantasy_points' => 40]);
    FixtureLineup::factory()->create(['fixture_id' => $second->id, 'player_id' => $soldBeforeWeekTwo->id, 'fantasy_points' => 50]);
    FixtureLineup::factory()->create(['fixture_id' => $first->id, 'player_id' => $lateJoinersPlayer->id, 'fantasy_points' => 9]);
    $weekTwoMiss = FixtureLineup::factory()->create(['fixture_id' => $second->id, 'player_id' => $signedForWeekTwo->id, 'fantasy_points' => 7]);

    $rows = collect(app(BanquilloDeOro::class)->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);

    expect($rows[$manager->id]->value)->toBe(12)
        ->and($rows[$manager->id]->context['top_miss'])->toBe([
            'fixture_lineup_id' => $weekTwoMiss->id, 'fixture_id' => $second->id, 'player_id' => $signedForWeekTwo->id, 'week_number' => 2, 'points' => 7,
        ])
        ->and($rows[$lateJoiner->id]->value)->toBe(0)
        ->and($rows[$lateJoiner->id]->context)->toBe(['top_miss' => null]);
});
