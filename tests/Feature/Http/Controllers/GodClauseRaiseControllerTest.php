<?php

use App\Enums\ClauseSnapshotSource;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(9)]);
    $this->manager = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $this->player = Player::factory()->create();
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
