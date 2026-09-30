<?php

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayer;
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
    // Manager joined 1-sep: daily bonus 1…25-sep = 20 × 100.000 + 5 × 200.000 (21…25-sep) = 3.000.000.
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

test('the daily bonus counts in both ends and raises cost half: certain in both, possible only in the pessimistic end', function (): void {
    $certain = Player::factory()->create();
    $possible = Player::factory()->create();
    move($this, SeasonActivityType::Signing, $this->manager, null, 5_000_000, '2026-09-05 10:00', $certain);
    move($this, SeasonActivityType::Signing, $this->manager, null, 2_000_000, '2026-09-05 10:00', $possible);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->manager->id, 'player_id' => $certain->id, 'buyout_clause' => 8_000_000]);
    ManagerPlayer::factory()->create(['season_manager_id' => $this->manager->id, 'player_id' => $possible->id, 'buyout_clause' => 3_234_567]);

    $balance = app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->manager->id];

    expect($balance->activity)->toBe(93_000_000)
        ->and($balance->dailyBonus)->toBe(3_000_000)
        ->and($balance->sureRaises)->toBe(3_000_000)
        ->and($balance->possibleRaises)->toBe(1_234_567)
        ->and($balance->low())->toBe(93_882_717)
        ->and($balance->high())->toBe(94_500_000)
        ->and($balance->mid())->toBe(94_191_358);
});

test('a manager without joined_league counts the daily bonus from the season start', function (): void {
    $balance = app(ManagerBalances::class)->forSeason($this->season, $this->now)[$this->rival->id];

    // 1-jul … 25-sep: 87 days, 5 of them (21…25-sep) in the break.
    expect($balance->dailyBonus)->toBe(9_200_000);
});

test('the connected account uses its latest snapshot plus later moves, and its residual calibrates the rivals', function (): void {
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'money' => 103_500_000, 'captured_at' => '2026-09-25 11:30',
    ]);
    move($this, SeasonActivityType::Signing, $this->manager, null, 5_000_000, '2026-09-25 11:45');

    $service = app(ManagerBalances::class);
    $balances = $service->forSeason($this->season, $this->now);

    // Real 98,5 M vs model 95 M + 3 M bonus = 98 M → +0,5 M over 25 days = 20.000 €/day.
    // The rival has been in the league 87 days → +1.740.000.
    expect($balances[$this->manager->id]->real)->toBe(98_500_000)
        ->and($balances[$this->manager->id]->low())->toBe(98_500_000)
        ->and($balances[$this->manager->id]->high())->toBe(98_500_000)
        ->and($balances[$this->rival->id]->calibration)->toBe(1_740_000)
        ->and($balances[$this->rival->id]->low())->toBe(100_000_000 + 9_200_000 + 1_740_000)
        ->and($service->connectedManagerId($this->season))->toBe($this->manager->id);
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
        ->and($balances[$this->rival->id]->calibration)->not->toBe(0);
});

test('without a snapshot there is no calibration', function (): void {
    $balances = app(ManagerBalances::class)->forSeason($this->season, $this->now);

    expect($balances[$this->rival->id]->calibration)->toBe(0)
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

    // 23:30 UTC is already 26-sep in Madrid: one more break day than on 25-sep.
    expect($balance->dailyBonus)->toBe(9_400_000);
});
