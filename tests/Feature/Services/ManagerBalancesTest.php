<?php

use App\Enums\ClauseSnapshotSource;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ManagerBalances;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-09-25 12:00');
    $this->travelTo($this->now);
    $this->season = Season::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    $this->manager = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $this->rival = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    // Manager joined 1-sep: daily bonus 1…25-sep = 20 × 100.000 + 5 × 200.000 (21…25-sep) = 3.000.000, claimed 67 % of days = 2.010.000.
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::JoinedLeague,
        'source_season_manager_id' => $this->manager->id, 'player_id' => null, 'amount' => null,
        'occurred_at' => '2026-09-01 10:00',
    ]);
});

function move(object $test, SeasonActivityType $type, SeasonManager $source, ?SeasonManager $target, ?int $amount, string $at = '2026-09-23 10:00', ?Player $player = null): void
{
    Activity::factory()->create([
        'season_id' => $test->season->id, 'type' => $type,
        'source_season_manager_id' => $source->id, 'target_season_manager_id' => $target?->id,
        'player_id' => $player?->id, 'amount' => $amount, 'occurred_at' => $at,
        'week_number' => $type === SeasonActivityType::WeeklyPrize ? 1 : null,
    ]);
}

test('the activity balance starts at 100 M and adds every money move in the right direction', function (): void {
    move($this, SeasonActivityType::Signing, $this->manager, null, 10_000_000);
    move($this, SeasonActivityType::Sale, $this->manager, null, 4_000_000);
    move($this, SeasonActivityType::Buyout, $this->manager, $this->rival, 3_000_000);
    move($this, SeasonActivityType::Buyout, $this->rival, $this->manager, 2_000_000);
    move($this, SeasonActivityType::WeeklyPrize, $this->manager, null, 1_000_000);
    move($this, SeasonActivityType::Shield, $this->manager, null, null);

    $balances = app(ManagerBalances::class)->forSeason($this->season, $this->now);

    expect($balances[$this->manager->id]->activity)->toBe(94_000_000)
        ->and($balances[$this->rival->id]->activity)->toBe(101_000_000);
});

test('the daily bonus counts 67 % in both ends and raises cost half: certain in both, possible only in the pessimistic end', function (): void {
    $certain = Player::factory()->create();
    $possible = Player::factory()->create();
    move($this, SeasonActivityType::Signing, $this->manager, null, 5_000_000, '2026-09-05 10:00', $certain);
    move($this, SeasonActivityType::Signing, $this->manager, null, 2_000_000, '2026-09-05 10:00', $possible);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->manager->id, 'player_id' => $certain->id, 'buyout_clause' => 8_000_000]);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->manager->id, 'player_id' => $possible->id, 'buyout_clause' => 3_234_567]);

    $balance = app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->manager->id];

    expect($balance->activity)->toBe(93_000_000)
        ->and($balance->dailyBonus)->toBe(2_010_000)
        ->and($balance->sureRaises)->toBe(3_000_000)
        ->and($balance->possibleRaises)->toBe(1_234_567)
        ->and($balance->low())->toBe(92_892_717)
        ->and($balance->high())->toBe(93_510_000)
        ->and($balance->mid())->toBe(93_201_358);
});

test('a manager without joined_league counts the daily bonus from the season start', function (): void {
    $balance = app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->rival->id];

    // 1-jul … 25-sep: 87 days, 5 of them (21…25-sep) in the break → 9.200.000, 67 % claimed.
    expect($balance->dailyBonus)->toBe(6_164_000);
});

test('the connected account uses its latest snapshot plus later moves; its residual is kept but never applied to rivals', function (): void {
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'money' => 103_500_000, 'captured_at' => '2026-09-25 11:30',
    ]);
    move($this, SeasonActivityType::Signing, $this->manager, null, 5_000_000, '2026-09-25 11:45');

    $service = app(ManagerBalances::class);
    $balances = $service->forSeason($this->season, $this->now);

    // Real 98,5 M vs model 95 M + 2,01 M bonus = 97,01 M → residual +1,49 M, not added to anyone.
    expect($balances[$this->manager->id]->real)->toBe(98_500_000)
        ->and($balances[$this->manager->id]->low())->toBe(98_500_000)
        ->and($balances[$this->manager->id]->high())->toBe(98_500_000)
        ->and($balances[$this->manager->id]->residual)->toBe(1_490_000)
        ->and($balances[$this->rival->id]->residual)->toBeNull()
        ->and($balances[$this->rival->id]->low())->toBe(100_000_000 + 6_164_000)
        ->and($balances[$this->rival->id]->high())->toBe(100_000_000 + 6_164_000)
        ->and($service->connectedManagerId($this->season))->toBe($this->manager->id);
});

test('moves before the snapshot are already in its money and do not count again in real cash', function (): void {
    move($this, SeasonActivityType::Sale, $this->manager, null, 4_000_000, '2026-09-25 11:00');
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'money' => 103_500_000, 'captured_at' => '2026-09-25 11:30',
    ]);
    move($this, SeasonActivityType::Signing, $this->manager, null, 5_000_000, '2026-09-25 11:45');

    expect(app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->manager->id]->real)->toBe(98_500_000);
});

test('a raise logged after the snapshot lowers real cash and does not inflate the residual', function (): void {
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'money' => 103_500_000, 'captured_at' => '2026-09-25 11:30',
    ]);
    $player = Player::factory()->create();
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $player->id, 'source' => ClauseSnapshotSource::Manual,
        'buyout_clause' => 6_000_000, 'market_value' => 0, 'raise_amount' => 2_000_000, 'captured_at' => '2026-09-25 11:50',
    ]);

    $balance = app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->manager->id];

    // Real 103,5 M − 1 M paid for the raise; model 100 M + 2,01 M − 1 M → residual +1,49 M, as without the raise.
    expect($balance->real)->toBe(102_500_000)
        ->and($balance->residual)->toBe(1_490_000);
});

test('only the connected account has real cash, even when an older snapshot belongs to a rival', function (): void {
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $this->rival->id, 'money' => 50_000_000, 'captured_at' => '2026-09-20 10:00',
    ]);
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'money' => 103_500_000, 'captured_at' => '2026-09-25 11:30',
    ]);

    $balances = app(ManagerBalances::class)->forSeason($this->season, $this->now);

    expect($balances[$this->manager->id]->real)->toBe(103_500_000)
        ->and($balances[$this->rival->id]->real)->toBeNull()
        ->and($balances[$this->rival->id]->residual)->toBeNull();
});

test('without a snapshot there is no residual and no connected account', function (): void {
    $balances = app(ManagerBalances::class)->forSeason($this->season, $this->now);

    expect($balances[$this->manager->id]->residual)->toBeNull()
        ->and(app(ManagerBalances::class)->connectedManagerId($this->season))->toBeNull();
});

test('squad value sums the current market value of the owned players and feeds the total', function (): void {
    foreach ([12_000_000, 3_500_000] as $value) {
        $player = Player::factory()->create(['market_value' => $value]);
        ManagerPlayer::factory()->create(['season_manager_id' => $this->rival->id, 'player_id' => $player->id, 'buyout_clause' => $value]);
    }

    $balance = app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->rival->id];

    expect($balance->squadValue)->toBe(15_500_000)
        ->and($balance->toArray()['total']['mid'])->toBe($balance->mid() + 15_500_000);
});

test('a negative balance is not clamped', function (): void {
    move($this, SeasonActivityType::Signing, $this->rival, null, 130_000_000);

    expect(app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->rival->id]->activity)
        ->toBe(-30_000_000);
});

test('the daily bonus counts days in the app timezone whatever the timezone of now', function (): void {
    config(['app.timezone' => 'Europe/Madrid']);
    $lateUtc = CarbonImmutable::parse('2026-09-25 23:30', 'UTC');

    $balance = app(ManagerBalances::class)->forSeason($this->season, $lateUtc)[$this->rival->id];

    // 23:30 UTC is already 26-sep in Madrid: one more break day than on 25-sep (9.400.000, 67 % claimed).
    expect($balance->dailyBonus)->toBe(6_298_000);
});
