<?php

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ClauseRaiseDetector;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->now = CarbonImmutable::parse('2026-09-29 12:00');
    $this->travelTo($this->now);
    $this->season = Season::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    $this->manager = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $this->rival = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $this->player = Player::factory()->create();
});

function acquire(object $test, SeasonManager $manager, int $amount, string $at, SeasonActivityType $type = SeasonActivityType::Signing, ?SeasonManager $from = null): void
{
    Activity::factory()->create([
        'season_id' => $test->season->id, 'type' => $type,
        'source_season_manager_id' => $manager->id, 'target_season_manager_id' => $from?->id,
        'player_id' => $test->player->id, 'amount' => $amount, 'occurred_at' => $at,
    ]);
}

function valueOn(object $test, string $date, int $value): void
{
    PlayerMarket::factory()->create(['player_id' => $test->player->id, 'date' => $date, 'value' => $value]);
}

function ownNow(object $test, SeasonManager $manager, int $clause): void
{
    ManagerPlayer::factory()->create([
        'season_manager_id' => $manager->id, 'player_id' => $test->player->id,
        'buyout_clause' => $clause, 'buyout_clause_locked_until' => '2026-09-15 12:00',
    ]);
}

function detect(object $test): array
{
    return (new ClauseRaiseDetector)->forSeason($test->season, $test->now);
}

test('a round raise at unlock over the price paid is certain', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    valueOn($this, '2026-09-10', 4_000_000);
    ownNow($this, $this->manager, 7_000_000);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 2_000_000, 'possible' => 0]]);
});

test('Koski: the raise is measured against the clause at unlock, not today', function (): void {
    acquire($this, $this->manager, 13_765_656, '2026-09-10 20:00');
    valueOn($this, '2026-09-23', 18_000_000);
    valueOn($this, '2026-09-24', 18_769_376);
    valueOn($this, '2026-09-28', 24_772_929);
    ownNow($this, $this->manager, 34_269_528);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 15_500_152, 'possible' => 0]]);
});

test('Otto: an unlock before the daily market update anchors on the previous day', function (): void {
    acquire($this, $this->manager, 16_667_688, '2026-09-14 00:54', SeasonActivityType::Buyout, $this->rival);
    valueOn($this, '2026-09-27', 17_623_163);
    valueOn($this, '2026-09-28', 17_939_259);
    ownNow($this, $this->manager, 59_623_163);

    expect(detect($this)[$this->manager->id])->toBe(['sure' => 42_000_000, 'possible' => 0]);
});

test('the base clause is at least 1 M and a clause that follows the value up is not a raise', function (): void {
    acquire($this, $this->manager, 800_000, '2026-09-01 12:00');
    valueOn($this, '2026-09-20', 900_000);
    valueOn($this, '2026-09-25', 12_300_000);
    ownNow($this, $this->manager, 12_300_000);

    expect(detect($this))->toBe([]);
});

test('a raise that no anchor explains as a round amount is only possible', function (): void {
    acquire($this, $this->manager, 10_000_000, '2026-09-01 12:00');
    ownNow($this, $this->manager, 11_234_567);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 0, 'possible' => 1_234_567]]);
});

test('a clause paid after the lock above the reference is a raise the victim paid for', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    valueOn($this, '2026-09-15', 5_432_100);
    acquire($this, $this->rival, 8_432_100, '2026-09-26 12:00', SeasonActivityType::Buyout, $this->manager);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 3_000_000, 'possible' => 0]]);
});

test('an accepted offer is not a raise: inside the lock or a round total', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    acquire($this, $this->rival, 9_734_512, '2026-09-04 12:00', SeasonActivityType::Buyout, $this->manager);
    acquire($this, $this->manager, 9_734_512, '2026-09-05 12:00', SeasonActivityType::Buyout, $this->rival);
    acquire($this, $this->rival, 17_000_000, '2026-09-25 12:00', SeasonActivityType::Buyout, $this->manager);

    expect(detect($this))->toBe([]);
});

test('an initial-squad player uses his value on the joining day as base and raises at unlock', function (): void {
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::JoinedLeague,
        'source_season_manager_id' => $this->manager->id, 'player_id' => null, 'amount' => null,
        'occurred_at' => '2026-08-01 20:00',
    ]);
    valueOn($this, '2026-08-01', 3_000_000);
    valueOn($this, '2026-09-10', 3_500_000);
    ownNow($this, $this->manager, 5_500_000);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 2_500_000, 'possible' => 0]]);
});

test('with clause history every jump above the value is an exact raise, even if not round', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    ownNow($this, $this->manager, 9_123_456);
    foreach ([['2026-09-20 10:00', 5_000_000, 4_000_000], ['2026-09-22 10:00', 9_123_456, 4_500_000]] as [$at, $clause, $value]) {
        ManagerPlayerClauseSnapshot::factory()->create([
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'buyout_clause' => $clause, 'market_value' => $value, 'captured_at' => $at,
            'buyout_clause_locked_until' => '2026-09-15 12:00',
        ]);
    }

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 4_123_456, 'possible' => 0]]);
});

test('a history jump that only follows the value is not a raise', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    ownNow($this, $this->manager, 6_200_000);
    foreach ([['2026-09-20 10:00', 5_000_000, 4_000_000], ['2026-09-22 10:00', 6_200_000, 6_200_000]] as [$at, $clause, $value]) {
        ManagerPlayerClauseSnapshot::factory()->create([
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'buyout_clause' => $clause, 'market_value' => $value, 'captured_at' => $at,
            'buyout_clause_locked_until' => '2026-09-15 12:00',
        ]);
    }

    expect(detect($this))->toBe([]);
});

test('a sale ends the holding: the next owner starts from his own price', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-08-01 12:00');
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Sale,
        'source_season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'amount' => 5_000_000, 'occurred_at' => '2026-08-20 12:00',
    ]);
    acquire($this, $this->rival, 2_000_000, '2026-09-01 12:00');
    ownNow($this, $this->rival, 2_000_000);

    expect(detect($this))->toBe([]);
});

test('a sell-and-rebuy does not bridge the clause history of two holdings into a raise', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-08-01 12:00');
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'buyout_clause' => 5_000_000, 'market_value' => 4_000_000, 'captured_at' => '2026-08-20 10:00',
        'buyout_clause_locked_until' => '2026-08-15 12:00',
    ]);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Sale,
        'source_season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'amount' => 4_000_000, 'occurred_at' => '2026-08-25 12:00',
    ]);
    acquire($this, $this->manager, 9_000_000, '2026-09-10 12:00');
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'buyout_clause' => 9_000_000, 'market_value' => 4_500_000, 'captured_at' => '2026-09-10 12:01',
        'buyout_clause_locked_until' => '2026-09-24 12:00',
    ]);
    ownNow($this, $this->manager, 9_000_000);

    expect(detect($this))->toBe([]);
});

test('an initial-squad holding without market history is skipped, not read as a raise', function (): void {
    ownNow($this, $this->manager, 20_000_000);

    expect(detect($this))->toBe([]);
});
