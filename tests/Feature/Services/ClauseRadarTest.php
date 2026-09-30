<?php

use App\Enums\ClauseState;
use App\Enums\PayerLevel;
use App\Enums\PlayerPosition;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ClauseRadar;
use App\Services\ManagerBalance;
use Carbon\CarbonImmutable;

function balanceOf(int $managerId, int $low, int $high): ManagerBalance
{
    // Base = high, no bonus; possible raises of 2 × (high − low) cost exactly high − low in the pessimistic end.
    return new ManagerBalance($managerId, $high, 0, 0, 2 * ($high - $low), null, 0);
}

test('the state is listed, then shielded, then locked, else open', function (): void {
    $now = CarbonImmutable::parse('2026-09-29 12:00');
    $entry = ManagerPlayer::factory()->make([
        'buyout_clause_locked_until' => $now->addDay(), 'shielded' => true, 'shielded_until' => $now->addHour(),
    ]);

    expect(ClauseRadar::state($entry, true, $now))->toBe(ClauseState::Listed)
        ->and(ClauseRadar::state($entry, false, $now))->toBe(ClauseState::Shielded);

    $entry->shielded_until = $now->subMinute();
    expect(ClauseRadar::state($entry, false, $now))->toBe(ClauseState::Locked);

    $entry->buyout_clause_locked_until = $now;
    expect(ClauseRadar::state($entry, false, $now))->toBe(ClauseState::Open);
});

test('opportunity rewards a clause near the value and a scoring player', function (): void {
    $radar = app(ClauseRadar::class);

    expect($radar->opportunity(10_000_000, 12_000_000, 5.0))->toBe(100)
        ->and($radar->opportunity(20_000_000, 10_000_000, 2.5))->toBe(26);
});

test('payer level is sure with the pessimistic end, maybe with the optimistic one, else no', function (): void {
    $balance = balanceOf(1, 50_000_000, 60_000_000);

    expect(ClauseRadar::payerLevel($balance, 50_000_000))->toBe(PayerLevel::Sure)
        ->and(ClauseRadar::payerLevel($balance, 55_000_000))->toBe(PayerLevel::Maybe)
        ->and(ClauseRadar::payerLevel($balance, 60_000_001))->toBe(PayerLevel::No);
});

test('rows list rivals as payers without the owner or the connected account, and skip players without a season row', function (): void {
    $now = CarbonImmutable::parse('2026-09-29 12:00');
    $this->travelTo($now);
    $season = Season::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    [$owner, $me, $rival] = SeasonManager::factory()->count(3)->create(['season_id' => $season->id])->all();
    // PlayerFactory creates the player's row for the current season (this one).
    $player = Player::factory()->create([
        'nickname' => 'Koski', 'position' => PlayerPosition::Defender, 'market_value' => 23_800_000, 'average_points' => 7.1,
    ]);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $owner->id, 'player_id' => $player->id,
        'buyout_clause' => 34_270_000, 'buyout_clause_locked_until' => $now->subDay(),
    ]);
    $unsynced = Player::factory()->create();
    PlayerSeason::query()->where('player_id', $unsynced->id)->delete();
    ManagerPlayer::factory()->create(['season_manager_id' => $owner->id, 'player_id' => $unsynced->id]);
    MarketPlayer::factory()->create(['player_id' => Player::factory()->create()->id]);

    $rows = app(ClauseRadar::class)->forSeason($season, [
        $owner->id => balanceOf($owner->id, 1, 1),
        $me->id => balanceOf($me->id, 200_000_000, 200_000_000),
        $rival->id => balanceOf($rival->id, 30_000_000, 40_000_000),
    ], $me->id, $now);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['player']['nickname'])->toBe('Koski')
        ->and($rows[0]['state'])->toBe('open')
        ->and($rows[0]['owner_id'])->toBe($owner->id)
        ->and($rows[0]['payers'])->toBe([['manager_id' => $rival->id, 'level' => 'maybe']]);
});

test('market rows list the live listings with the seller, and payers without the seller or the connected account', function (): void {
    $now = CarbonImmutable::parse('2026-09-29 12:00');
    $this->travelTo($now);
    $season = Season::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    [$seller, $me, $rich, $poor] = SeasonManager::factory()->count(4)->create(['season_id' => $season->id])->all();
    $free = Player::factory()->create(['nickname' => 'Libre', 'position' => PlayerPosition::Striker, 'market_value' => 9_000_000]);
    $owned = Player::factory()->create(['nickname' => 'Vendido', 'position' => PlayerPosition::Midfield]);
    ManagerPlayer::factory()->create(['season_manager_id' => $seller->id, 'player_id' => $owned->id]);
    MarketPlayer::factory()->create([
        'player_id' => $free->id, 'sale_price' => 10_000_000, 'value' => 9_000_000, 'bids' => 2, 'expires_at' => $now->addHours(5),
    ]);
    MarketPlayer::factory()->create(['player_id' => $owned->id, 'sale_price' => 20_000_000, 'expires_at' => $now->addHours(3)]);
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create(['position' => PlayerPosition::Defender])->id, 'expires_at' => $now->subMinute(),
    ]);
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create(['position' => PlayerPosition::Coach])->id, 'expires_at' => $now->addHour(),
    ]);
    $unsynced = Player::factory()->create();
    PlayerSeason::query()->where('player_id', $unsynced->id)->delete();
    MarketPlayer::factory()->create(['player_id' => $unsynced->id, 'expires_at' => $now->addHour()]);

    $rows = app(ClauseRadar::class)->marketRows($season, [
        $seller->id => balanceOf($seller->id, 500_000_000, 500_000_000),
        $me->id => balanceOf($me->id, 500_000_000, 500_000_000),
        $rich->id => balanceOf($rich->id, 15_000_000, 25_000_000),
        $poor->id => balanceOf($poor->id, 1_000_000, 2_000_000),
    ], $me->id, $now);

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['player']['nickname'])->toBe('Vendido')
        ->and($rows[0]['seller_id'])->toBe($seller->id)
        ->and($rows[0]['payers'])->toBe([
            ['manager_id' => $rich->id, 'level' => 'maybe'],
            ['manager_id' => $poor->id, 'level' => 'no'],
        ])
        ->and($rows[1]['player']['nickname'])->toBe('Libre')
        ->and($rows[1]['player']['position'])->toBe('striker')
        ->and($rows[1]['seller_id'])->toBeNull()
        ->and($rows[1]['price'])->toBe(10_000_000)
        ->and($rows[1]['value'])->toBe(9_000_000)
        ->and($rows[1]['bids'])->toBe(2)
        ->and($rows[1]['expires_at'])->toBe($now->addHours(5)->toIso8601String())
        ->and($rows[1]['payers'])->toBe([
            ['manager_id' => $seller->id, 'level' => 'sure'],
            ['manager_id' => $rich->id, 'level' => 'sure'],
            ['manager_id' => $poor->id, 'level' => 'no'],
        ]);
});

test('an empty market has no rows', function (): void {
    $season = Season::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);

    expect(app(ClauseRadar::class)->marketRows($season, [], null, CarbonImmutable::now()))->toBe([]);
});

test('only a live market listing makes a clause listed, not an expired one', function (): void {
    $now = CarbonImmutable::parse('2026-09-29 12:00');
    $this->travelTo($now);
    $season = Season::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    $owner = SeasonManager::factory()->create(['season_id' => $season->id]);
    [$expired, $live] = Player::factory()->count(2)->sequence(['nickname' => 'Caducado'], ['nickname' => 'Vivo'])->create()->all();
    foreach ([$expired, $live] as $player) {
        ManagerPlayer::factory()->create([
            'season_manager_id' => $owner->id, 'player_id' => $player->id, 'shielded' => false, 'buyout_clause_locked_until' => $now->subDay(),
        ]);
    }
    MarketPlayer::factory()->create(['player_id' => $expired->id, 'expires_at' => $now->subHour()]);
    MarketPlayer::factory()->create(['player_id' => $live->id, 'expires_at' => $now->addHour()]);

    $states = collect(app(ClauseRadar::class)->forSeason($season, [$owner->id => balanceOf($owner->id, 1, 1)], null, $now))
        ->mapWithKeys(fn (array $row): array => [$row['player']['nickname'] => $row['state']])
        ->all();

    expect($states)->toBe(['Caducado' => 'open', 'Vivo' => 'listed']);
});
