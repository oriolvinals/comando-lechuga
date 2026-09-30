<?php

use App\Enums\ClauseSnapshotSource;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ManagerBalances;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(9)]);
    $this->manager = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $this->player = Player::factory()->create();
    ManagerPlayer::factory()->create(['season_manager_id' => $this->manager->id, 'player_id' => $this->player->id, 'buyout_clause' => 17_623_163]);
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'buyout_clause' => 17_623_163, 'captured_at' => now()->subDays(3),
    ]);
});

test('manual raises are god only', function (): void {
    $this->post(route('god.clause-raises.store'), [])->assertNotFound();
});

test('storing a new clause derives the raise and its cost', function (): void {
    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'captured_at' => now()->subDay()->toDateTimeString(), 'new_clause' => 59_623_163, 'note' => 'Otto, «hasta los 59 M»',
        ])
        ->assertRedirect(route('god.radar'));

    $manual = ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->sole();
    expect($manual->buyout_clause)->toBe(59_623_163)
        ->and($manual->raise_amount)->toBe(42_000_000)
        ->and($manual->note)->toBe('Otto, «hasta los 59 M»');
});

test('storing the amount paid derives the raise (×2) and the new clause', function (): void {
    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'captured_at' => now()->subDay()->toDateTimeString(), 'paid' => 21_000_000,
        ]);

    $manual = ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->sole();
    expect($manual->raise_amount)->toBe(42_000_000)
        ->and($manual->buyout_clause)->toBe(59_623_163);
});

test('validation needs exactly one of new clause or paid, and a raise above the previous clause', function (array $payload, string $error): void {
    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'captured_at' => now()->subDay()->toDateTimeString(), ...$payload,
        ])
        ->assertSessionHasErrors($error);
})->with([
    'neither' => [[], 'new_clause'],
    'both' => [['new_clause' => 60_000_000, 'paid' => 1_000_000], 'new_clause'],
    'not above the previous clause' => [['new_clause' => 17_000_000], 'new_clause'],
    'negative paid' => [['paid' => -5], 'paid'],
]);

test('only manual rows can be edited or deleted', function (): void {
    $sync = ManagerPlayerClauseSnapshot::query()->sole();

    $this->withCookie('god_mode', '1')->delete(route('god.clause-raises.destroy', $sync))->assertNotFound();

    $manual = ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'source' => ClauseSnapshotSource::Manual, 'buyout_clause' => 20_000_000, 'raise_amount' => 2_376_837,
    ]);

    $this->withCookie('god_mode', '1')
        ->put(route('god.clause-raises.update', $manual), [
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'captured_at' => now()->subDay()->toDateTimeString(), 'paid' => 1_000_000, 'note' => 'corregido',
        ])
        ->assertRedirect(route('god.radar'));
    expect($manual->refresh()->raise_amount)->toBe(2_000_000);

    $this->withCookie('god_mode', '1')->delete(route('god.clause-raises.destroy', $manual))->assertRedirect(route('god.radar'));
    expect(ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->count())->toBe(0);
});

test('editing and deleting are god only too', function (): void {
    $manual = ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'source' => ClauseSnapshotSource::Manual, 'buyout_clause' => 20_000_000, 'raise_amount' => 2_376_837,
    ]);

    $this->put(route('god.clause-raises.update', $manual), ['paid' => 1_000_000])->assertNotFound();
    $this->delete(route('god.clause-raises.destroy', $manual))->assertNotFound();

    expect($manual->refresh()->raise_amount)->toBe(2_376_837);
});

test('a raise entered by hand replaces the sync jump in the balances, even with a different figure', function (string $field, int $amount, string $time, int $sureRaises): void {
    $player = Player::factory()->create();
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Signing, 'source_season_manager_id' => $this->manager->id,
        'player_id' => $player->id, 'amount' => 13_765_656, 'occurred_at' => now()->subDays(20),
    ]);
    $day = CarbonImmutable::now()->subDays(5)->startOfDay();
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => $day->toDateString(), 'value' => 18_769_376]);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
        'buyout_clause' => 34_269_528, 'buyout_clause_locked_until' => now()->subDays(6),
    ]);
    foreach ([['19:00', 18_769_376], ['21:00', 34_269_528]] as [$at, $clause]) {
        ManagerPlayerClauseSnapshot::factory()->create([
            'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
            'buyout_clause' => $clause, 'market_value' => 18_769_376, 'captured_at' => $day->setTimeFromTimeString($at),
        ]);
    }

    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
            'captured_at' => $day->setTimeFromTimeString($time)->toDateTimeString(), $field => $amount,
        ])
        ->assertSessionHasNoErrors();

    $balances = app(ManagerBalances::class)->forSeason($this->season, CarbonImmutable::now());
    expect($balances[$this->manager->id]->sureRaises)->toBe($sureRaises);
})->with([
    'paid, a figure other than the sync jump' => ['paid', 7_500_000, '20:30', 15_000_000],
    'new clause, entered after the sync already saw it' => ['new_clause', 34_269_528, '21:30', 15_500_152],
]);

test('the previous clause belongs to the current holding, not one before a sell-and-rebuy', function (): void {
    $player = Player::factory()->create();
    foreach ([[SeasonActivityType::Signing, 5_000_000, 30], [SeasonActivityType::Sale, 6_000_000, 20], [SeasonActivityType::Signing, 8_000_000, 10]] as [$type, $amount, $daysAgo]) {
        Activity::factory()->create([
            'season_id' => $this->season->id, 'type' => $type, 'source_season_manager_id' => $this->manager->id,
            'player_id' => $player->id, 'amount' => $amount, 'occurred_at' => now()->subDays($daysAgo),
        ]);
    }
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
        'buyout_clause' => 40_000_000, 'captured_at' => now()->subDays(25),
    ]);

    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
            'captured_at' => now()->subDays(2)->toDateTimeString(), 'new_clause' => 10_000_000,
        ])
        ->assertSessionHasNoErrors();

    expect(ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->sole()->raise_amount)->toBe(2_000_000);
});

test('the manager must belong to the current season', function (): void {
    $oldSeason = Season::factory()->create(['start_date' => now()->subYears(2), 'end_date' => now()->subYear()]);

    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => SeasonManager::factory()->create(['season_id' => $oldSeason->id])->id, 'player_id' => $this->player->id,
            'captured_at' => now()->subDay()->toDateTimeString(), 'paid' => 1_000_000,
        ])
        ->assertSessionHasErrors('season_manager_id');
});

test('the previous clause is at least the highest value since the purchase, not only the value that day', function (): void {
    $player = Player::factory()->create();
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Signing, 'source_season_manager_id' => $this->manager->id,
        'player_id' => $player->id, 'amount' => 5_000_000, 'occurred_at' => now()->subDays(10),
    ]);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->manager->id, 'player_id' => $player->id, 'buyout_clause' => 10_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(12)->toDateString(), 'value' => 12_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(8)->toDateString(), 'value' => 9_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(3)->toDateString(), 'value' => 7_000_000]);

    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
            'captured_at' => now()->subDays(2)->toDateTimeString(), 'new_clause' => 10_000_000,
        ])
        ->assertSessionHasNoErrors();

    expect(ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->sole()->raise_amount)->toBe(1_000_000);
});

test('an initial-squad player\'s previous clause is at least 5/3 of his value on the joining day', function (): void {
    $player = Player::factory()->create();
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::JoinedLeague, 'source_season_manager_id' => $this->manager->id,
        'player_id' => null, 'amount' => null, 'occurred_at' => now()->subDays(20),
    ]);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->manager->id, 'player_id' => $player->id, 'buyout_clause' => 6_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(20)->toDateString(), 'value' => 3_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(3)->toDateString(), 'value' => 4_000_000]);

    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
            'captured_at' => now()->subDays(2)->toDateTimeString(), 'new_clause' => 6_000_000,
        ])
        ->assertSessionHasNoErrors();

    expect(ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->sole()->raise_amount)->toBe(1_000_000);
});

test('a raise dated when the manager did not own the player is rejected, so it can never be counted twice', function (string $when): void {
    $player = Player::factory()->create();
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Signing, 'source_season_manager_id' => $this->manager->id,
        'player_id' => $player->id, 'amount' => 5_000_000, 'occurred_at' => now()->subDays(10),
    ]);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Sale, 'source_season_manager_id' => $this->manager->id,
        'player_id' => $player->id, 'amount' => 5_500_000, 'occurred_at' => now()->subDays(4),
    ]);

    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $player->id,
            'captured_at' => now()->subDays((int) $when)->toDateTimeString(), 'paid' => 1_000_000,
        ])
        ->assertSessionHasErrors('captured_at');

    expect(ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->count())->toBe(0);
})->with([
    'before the purchase' => ['11'],
    'after the sale' => ['2'],
]);

test('a raise dated in the future is rejected, so it can never be counted twice', function (): void {
    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'captured_at' => now()->addHour()->toDateTimeString(), 'new_clause' => 59_623_163,
        ])
        ->assertSessionHasErrors('captured_at');

    expect(ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->count())->toBe(0);
});

test('an initial-squad raise dated before the manager joined the league is rejected', function (): void {
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::JoinedLeague, 'source_season_manager_id' => $this->manager->id,
        'player_id' => null, 'amount' => null, 'occurred_at' => now()->subDays(5),
    ]);

    $this->withCookie('god_mode', '1')
        ->post(route('god.clause-raises.store'), [
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'captured_at' => now()->subDays(6)->toDateTimeString(), 'new_clause' => 59_623_163,
        ])
        ->assertSessionHasErrors('captured_at');

    expect(ManagerPlayerClauseSnapshot::query()->where('source', ClauseSnapshotSource::Manual)->count())->toBe(0);
});
