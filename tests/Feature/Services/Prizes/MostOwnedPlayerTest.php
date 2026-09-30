<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\MostOwnedPlayer;
use App\Services\Prizes\PrizeRow;

test('picks the player with most distinct owners and counts the jornadas each one held him', function (): void {
    $season = Season::factory()->create(['current_week' => 5, 'total_weeks' => 38]);
    foreach ([1 => '2026-08-15 19:00:00', 2 => '2026-08-22 19:00:00', 3 => '2026-08-29 19:00:00', 4 => '2026-09-05 19:00:00'] as $week => $kickoff) {
        Fixture::factory()->create(['season_id' => $season->id, 'week_number' => $week, 'date' => $kickoff, 'state' => FixtureState::Finished]);
    }
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 5, 'date' => '2026-09-12 19:00:00', 'state' => FixtureState::FirstHalf]);

    [$dubi, $cid, $cruza, $gau] = SeasonManager::factory()->count(4)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    $vlachodimos = Player::factory()->create();
    $boring = Player::factory()->create();

    $move = fn (Player $player, SeasonActivityType $type, SeasonManager $source, ?SeasonManager $target, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'player_id' => $player->id,
        'source_season_manager_id' => $source->id, 'target_season_manager_id' => $target?->id, 'occurred_at' => $at,
    ]);

    $move($vlachodimos, SeasonActivityType::Sale, $dubi, null, '2026-08-10 10:00:00');
    $move($vlachodimos, SeasonActivityType::Signing, $cid, null, '2026-08-11 20:00:00');
    $move($vlachodimos, SeasonActivityType::Buyout, $cruza, $cid, '2026-08-27 09:00:00');
    $move($vlachodimos, SeasonActivityType::Sale, $cruza, null, '2026-08-27 18:00:00');
    $move($vlachodimos, SeasonActivityType::Signing, $cid, null, '2026-09-01 20:00:00');
    $move($boring, SeasonActivityType::Signing, $gau, null, '2026-08-11 20:00:00');

    $mostOwned = app(MostOwnedPlayer::class);
    $candidates = $mostOwned->candidates($season);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['player_id'])->toBe($vlachodimos->id)
        ->and($candidates[0]['chain'])->toBe([$dubi->id, $cid->id, $cruza->id, $cid->id])
        ->and($candidates[0]['owners'])->toBe([$dubi->id, $cid->id, $cruza->id])
        ->and($candidates[0]['transfers'])->toBe(3)
        ->and($candidates[0]['on_market'])->toBeFalse()
        ->and($candidates[0]['weeks_held'])->toBe([$dubi->id => 0, $cid->id => 3, $cruza->id => 0])
        ->and($candidates[0]['winners'])->toBe([$cid->id]);

    $rows = collect($mostOwned->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);

    expect($rows[$cid->id]->value)->toBe(3)
        ->and($rows[$cid->id]->context)->toBe(['player_id' => $vlachodimos->id])
        ->and($rows[$cruza->id]->value)->toBe(0)
        ->and($rows[$gau->id]->value)->toBeNull();
});

test('keeps every player tied on owners, each with its own winner', function (): void {
    $season = Season::factory()->create(['current_week' => 2, 'total_weeks' => 38]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::Scheduled]);

    [$dubi, $cid, $cruza, $gau] = SeasonManager::factory()->count(4)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    [$first, $second] = Player::factory()->count(2)->create()->all();

    $move = fn (Player $player, SeasonActivityType $type, SeasonManager $source, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'player_id' => $player->id,
        'source_season_manager_id' => $source->id, 'target_season_manager_id' => null, 'occurred_at' => $at,
    ]);

    $move($first, SeasonActivityType::Sale, $dubi, '2026-08-10 10:00:00');
    $move($first, SeasonActivityType::Signing, $cid, '2026-08-11 20:00:00');
    $move($second, SeasonActivityType::Sale, $cruza, '2026-08-10 10:00:00');
    $move($second, SeasonActivityType::Signing, $gau, '2026-08-12 20:00:00');
    $move($second, SeasonActivityType::Sale, $gau, '2026-08-20 10:00:00');

    $mostOwned = app(MostOwnedPlayer::class);
    $candidates = collect($mostOwned->candidates($season))->keyBy('player_id');

    expect($candidates)->toHaveCount(2)
        ->and($candidates[$first->id]['winners'])->toBe([$cid->id])
        ->and($candidates[$second->id]['winners'])->toBe([$gau->id])
        ->and($candidates[$second->id]['on_market'])->toBeTrue()
        ->and($candidates[$second->id]['transfers'])->toBe(2);

    $rows = collect($mostOwned->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);

    expect($rows[$cid->id]->value)->toBe(1)
        ->and($rows[$cid->id]->context)->toBe(['player_id' => $first->id])
        ->and($rows[$gau->id]->value)->toBe(1)
        ->and($rows[$gau->id]->context)->toBe(['player_id' => $second->id])
        ->and($rows[$dubi->id]->value)->toBe(0);
});

test('credits a jornada to the owner at its main block, not at a match played early', function (): void {
    $season = Season::factory()->create(['current_week' => 3, 'total_weeks' => 38]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-12 21:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-23 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => '2026-08-29 19:00:00', 'state' => FixtureState::Scheduled]);
    [$planuky, $cid] = SeasonManager::factory()->count(2)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    $turrientes = Player::factory()->create();

    Activity::factory()->create([
        'season_id' => $season->id, 'type' => SeasonActivityType::Buyout, 'player_id' => $turrientes->id,
        'source_season_manager_id' => $cid->id, 'target_season_manager_id' => $planuky->id, 'occurred_at' => '2026-08-16 23:35:00',
    ]);

    $candidates = app(MostOwnedPlayer::class)->candidates($season);

    expect($candidates)->toHaveCount(1)
        ->and($candidates[0]['weeks_held'])->toBe([$planuky->id => 1, $cid->id => 1])
        ->and($candidates[0]['winners'])->toBe([$planuky->id, $cid->id]);
});
