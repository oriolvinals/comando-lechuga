<?php

use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(9)]);
    $this->managers = SeasonManager::factory()->count(2)->sequence(['position' => 1], ['position' => 2])
        ->create(['season_id' => $this->season->id]);
});

test('the radar does not exist without god mode', function (): void {
    $this->get('/radar')->assertNotFound();
});

test('with god mode the radar renders every manager as a range when there is no snapshot', function (): void {
    $this->withCookie('god_mode', '1')
        ->get(route('god.radar'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('god/radar')
            ->where('connectedManagerId', null)
            ->has('managers', 2)
            ->where('managers.0.id', $this->managers[0]->id)
            ->where('managers.0.cash.is_real', false)
            ->has('managers.0.total.mid')
            ->has('managers.0.shields.remaining')
            ->has('clauses')
            ->has('now'));
});

test('the connected account is the owner of the latest snapshot and shows real cash', function (): void {
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $this->managers[1]->id, 'money' => 254_969_545, 'captured_at' => now()->subMinutes(10),
    ]);

    $this->withCookie('god_mode', '1')
        ->get(route('god.radar'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('connectedManagerId', $this->managers[1]->id)
            ->where('managers.1.cash.is_real', true)
            ->where('managers.1.cash.mid', 254_969_545));
});

test('the radar url is a clean /radar', function (): void {
    expect(route('god.radar', absolute: false))->toBe('/radar');
});

test('without a snapshot every rival of the owner can be a payer', function (): void {
    $third = SeasonManager::factory()->create(['season_id' => $this->season->id, 'position' => 3]);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->managers[0]->id, 'player_id' => Player::factory()->create()->id]);

    $this->withCookie('god_mode', '1')
        ->get(route('god.radar'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('connectedManagerId', null)
            ->has('clauses', 1)
            ->where('clauses.0.owner_id', $this->managers[0]->id)
            ->where('clauses.0.payers', fn ($payers): bool => collect($payers)->pluck('manager_id')->sort()->values()->all()
                === [$this->managers[1]->id, $third->id]));
});
