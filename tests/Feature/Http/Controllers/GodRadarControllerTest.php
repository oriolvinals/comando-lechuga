<?php

use App\Enums\ClauseSnapshotSource;
use App\Enums\PlayerPosition;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\MarketPlayer;
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

test('without a snapshot every rival of the owner can be a payer, in ranking order', function (): void {
    $this->managers[1]->update(['position' => 4, 'fantasy_id' => 100_000_001]);
    $third = SeasonManager::factory()->create(['season_id' => $this->season->id, 'position' => 3, 'fantasy_id' => 100_000_002]);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->managers[0]->id, 'player_id' => Player::factory()->create()->id]);

    $this->withCookie('god_mode', '1')
        ->get(route('god.radar'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('connectedManagerId', null)
            ->has('clauses', 1)
            ->where('clauses.0.owner_id', $this->managers[0]->id)
            ->where('clauses.0.payers', fn ($payers): bool => collect($payers)->pluck('manager_id')->all()
                === [$third->id, $this->managers[1]->id]));
});

test('the radar lists this season\'s manual clause raises with their cost', function (): void {
    $player = Player::factory()->create(['nickname' => 'Otto']);
    $manual = ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->managers[0]->id, 'player_id' => $player->id, 'source' => ClauseSnapshotSource::Manual,
        'buyout_clause' => 59_623_163, 'raise_amount' => 42_000_000, 'note' => 'hasta los 59 M', 'captured_at' => now()->subDay(),
    ]);
    ManagerPlayerClauseSnapshot::factory()->create(['season_manager_id' => $this->managers[0]->id, 'player_id' => $player->id]);
    $oldSeason = Season::factory()->create(['start_date' => now()->subYears(2), 'end_date' => now()->subYear()]);
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => SeasonManager::factory()->create(['season_id' => $oldSeason->id])->id,
        'source' => ClauseSnapshotSource::Manual, 'raise_amount' => 2_000_000,
    ]);

    $this->withCookie('god_mode', '1')
        ->get(route('god.radar'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('manualRaises', 1)
            ->where('manualRaises.0', [
                'id' => $manual->id,
                'player' => ['id' => $player->id, 'nickname' => 'Otto'],
                'manager_id' => $this->managers[0]->id,
                'captured_at' => $manual->captured_at->toIso8601String(),
                'clause' => 59_623_163,
                'raise' => 42_000_000,
                'cost' => 21_000_000,
                'note' => 'hasta los 59 M',
            ]));
});

test('the radar lists the players on the market', function (): void {
    $player = Player::factory()->create(['nickname' => 'Libre', 'position' => PlayerPosition::Striker]);
    MarketPlayer::factory()->create(['player_id' => $player->id, 'sale_price' => 10_000_000, 'expires_at' => now()->addHours(5)]);

    $this->withCookie('god_mode', '1')
        ->get(route('god.radar'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('market', 1)
            ->where('market.0.player.nickname', 'Libre')
            ->where('market.0.seller_id', null)
            ->where('market.0.price', 10_000_000)
            ->has('market.0.payers', 2));
});

test('the manual raise picker offers every player each manager owned this season, current or past', function (): void {
    [$first, $second] = [$this->managers[0], $this->managers[1]];
    $sold = Player::factory()->create(['nickname' => 'Vendido']);
    $boughtOut = Player::factory()->create(['nickname' => 'Ibañez']);
    $kept = Player::factory()->create(['nickname' => 'Titular']);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Signing, 'source_season_manager_id' => $first->id,
        'player_id' => $sold->id, 'amount' => 5_000_000, 'occurred_at' => now()->subDays(10),
    ]);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Sale, 'source_season_manager_id' => $first->id,
        'player_id' => $sold->id, 'amount' => 5_500_000, 'occurred_at' => now()->subDays(4),
    ]);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Signing, 'source_season_manager_id' => $first->id,
        'player_id' => $boughtOut->id, 'amount' => 9_000_000, 'occurred_at' => now()->subDays(20),
    ]);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Buyout, 'source_season_manager_id' => $second->id,
        'target_season_manager_id' => $first->id, 'player_id' => $boughtOut->id, 'amount' => 17_000_000, 'occurred_at' => now()->subDays(2),
    ]);
    ManagerPlayer::factory()->create(['season_manager_id' => $first->id, 'player_id' => $kept->id]);
    ManagerPlayer::factory()->create(['season_manager_id' => $second->id, 'player_id' => $boughtOut->id]);

    $this->withCookie('god_mode', '1')
        ->get(route('god.radar'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('raiseCandidates', fn ($candidates): bool => collect($candidates)
                ->map(fn (array $candidate): string => "{$candidate['manager_id']}:{$candidate['player']['nickname']}:".($candidate['current'] ? 'now' : 'past'))
                ->sort()->values()->all() === collect([
                    "{$first->id}:Ibañez:past",
                    "{$first->id}:Titular:now",
                    "{$first->id}:Vendido:past",
                    "{$second->id}:Ibañez:now",
                ])->sort()->values()->all()));
});
