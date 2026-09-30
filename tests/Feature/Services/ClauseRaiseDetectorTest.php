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

test('a buyout inside the victim\'s lock is an accepted offer, not a raise, even for a round total', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    acquire($this, $this->rival, 9_734_512, '2026-09-04 12:00', SeasonActivityType::Buyout, $this->manager);
    // Two hours before the unlock: still inside the lock.
    acquire($this, $this->manager, 17_000_000, '2026-09-18 10:00', SeasonActivityType::Buyout, $this->rival);

    expect(detect($this))->toBe([]);
});

test('Zubeldia: a round total paid after the lock is the clause, so the owner raised it', function (): void {
    acquire($this, $this->manager, 12_000_000, '2026-08-15 17:03', SeasonActivityType::Buyout, $this->rival);
    valueOn($this, '2026-08-15', 9_937_308);
    valueOn($this, '2026-09-04', 8_625_421);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Shield,
        'source_season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'amount' => null, 'occurred_at' => '2026-09-02 23:49',
    ]);
    acquire($this, $this->rival, 14_000_000, '2026-09-04 21:00', SeasonActivityType::Buyout, $this->manager);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 2_000_000, 'possible' => 0]]);
});

function joinLeague(object $test, SeasonManager $manager, string $at = '2026-08-01 20:00'): void
{
    Activity::factory()->create([
        'season_id' => $test->season->id, 'type' => SeasonActivityType::JoinedLeague,
        'source_season_manager_id' => $manager->id, 'player_id' => null, 'amount' => null,
        'occurred_at' => $at,
    ]);
}

test('Moncayola: an initial-squad clause starts at 5/3 of the value on the joining day, so paying it is no raise', function (): void {
    joinLeague($this, $this->manager);
    valueOn($this, '2026-08-01', 5_514_269);
    valueOn($this, '2026-08-18', 6_905_144);
    acquire($this, $this->rival, 9_190_448, '2026-08-19 00:43', SeasonActivityType::Buyout, $this->manager);

    expect(detect($this))->toBe([]);
});

test('an initial-squad raise at unlock is measured over 5/3 of the joining value', function (): void {
    joinLeague($this, $this->manager);
    valueOn($this, '2026-08-01', 3_000_000);
    valueOn($this, '2026-09-10', 3_500_000);
    ownNow($this, $this->manager, 7_500_000);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 2_500_000, 'possible' => 0]]);
});

test('an initial-squad clause follows the value once it passes 5/3 of the joining value', function (): void {
    joinLeague($this, $this->manager);
    valueOn($this, '2026-08-01', 3_000_000);
    valueOn($this, '2026-09-10', 8_000_000);
    ownNow($this, $this->manager, 8_000_000);

    expect(detect($this))->toBe([]);
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

test('a manual raise is authoritative: it replaces the inference and a matching sync jump is not counted twice', function (): void {
    acquire($this, $this->manager, 13_765_656, '2026-09-10 20:00');
    valueOn($this, '2026-09-24', 18_769_376);
    ownNow($this, $this->manager, 34_269_528);
    foreach ([['2026-09-24 19:00', 18_769_376, ClauseSnapshotSource::Sync, 0], ['2026-09-24 21:00', 34_269_528, ClauseSnapshotSource::Sync, 0], ['2026-09-24 20:30', 34_269_528, ClauseSnapshotSource::Manual, 15_000_000]] as [$at, $clause, $source, $raise]) {
        ManagerPlayerClauseSnapshot::factory()->create([
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'buyout_clause' => $clause, 'market_value' => 18_769_376, 'captured_at' => $at,
            'source' => $source, 'raise_amount' => $raise,
        ]);
    }

    // Inference and the sync jump would both say 15.500.152; the user's own figure wins, once.
    expect(detect($this))->toBe([$this->manager->id => ['sure' => 15_000_000, 'possible' => 0]]);
});

function manualRaise(object $test, SeasonManager $manager, int $raise, int $clause, string $at): void
{
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $manager->id, 'player_id' => $test->player->id, 'source' => ClauseSnapshotSource::Manual,
        'buyout_clause' => $clause, 'market_value' => 0, 'raise_amount' => $raise, 'captured_at' => $at,
    ]);
}

test('a manual raise counts even when its holding ended in a market sale', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    valueOn($this, '2026-09-01', 4_000_000);
    manualRaise($this, $this->manager, 2_000_000, 7_000_000, '2026-09-15 13:00');
    acquire($this, $this->manager, 6_000_000, '2026-09-20 12:00', SeasonActivityType::Sale);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 2_000_000, 'possible' => 0]]);
});

test('a manual raise counts even for a holding skipped for lacking market history', function (): void {
    ownNow($this, $this->manager, 20_000_000);
    manualRaise($this, $this->manager, 3_000_000, 20_000_000, '2026-09-20 12:00');

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 3_000_000, 'possible' => 0]]);
});

test('a manual raise that differs from the sync jump near it still replaces it', function (): void {
    acquire($this, $this->manager, 13_765_656, '2026-09-10 20:00');
    valueOn($this, '2026-09-24', 18_769_376);
    ownNow($this, $this->manager, 34_269_528);
    foreach ([['2026-09-24 19:00', 18_769_376], ['2026-09-24 21:00', 34_269_528]] as [$at, $clause]) {
        ManagerPlayerClauseSnapshot::factory()->create([
            'season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'buyout_clause' => $clause, 'market_value' => 18_769_376, 'captured_at' => $at,
        ]);
    }
    manualRaise($this, $this->manager, 15_000_000, 33_769_376, '2026-09-24 20:30');

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 15_000_000, 'possible' => 0]]);
});

test('it tells how much of the sure raises happened after a moment', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    ownNow($this, $this->manager, 7_000_000);
    $other = Player::factory()->create();
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $this->manager->id, 'player_id' => $other->id, 'source' => ClauseSnapshotSource::Manual,
        'buyout_clause' => 4_000_000, 'market_value' => 0, 'raise_amount' => 1_000_000, 'captured_at' => '2026-09-28 22:30',
    ]);
    $detector = new ClauseRaiseDetector;
    $detector->forSeason($this->season, $this->now);

    // The 2 M raise anchors on the unlock (15-sep 12:00); the manual one was logged on 28-sep 22:30.
    expect($detector->sureRaisedAfter($this->manager->id, CarbonImmutable::parse('2026-09-10 00:00')))->toBe(3_000_000)
        ->and($detector->sureRaisedAfter($this->manager->id, CarbonImmutable::parse('2026-09-28 22:11')))->toBe(1_000_000)
        ->and($detector->sureRaisedAfter($this->rival->id, CarbonImmutable::parse('2026-09-01 00:00')))->toBe(0);
});

test('Ibañez: the 17 M paid 15 minutes before the computed unlock is the clause, so the owner may have raised it', function (): void {
    acquire($this, $this->manager, 9_659_760, '2026-09-12 14:03:56', SeasonActivityType::Buyout, $this->rival);
    valueOn($this, '2026-09-12', 9_659_760);
    valueOn($this, '2026-09-26', 13_934_560);
    acquire($this, $this->rival, 17_000_000, '2026-09-26 13:48:21', SeasonActivityType::Buyout, $this->manager);

    // Inside the last hour before purchase + 14 d: 17 M over the 13.934.560 value that day.
    expect(detect($this))->toBe([$this->manager->id => ['sure' => 0, 'possible' => 3_065_440]]);
});

test('a buyout in the last hour of the computed lock is a clause payment; one earlier is an offer', function (string $at, array $expected): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    acquire($this, $this->rival, 7_000_000, $at, SeasonActivityType::Buyout, $this->manager);

    expect(detect($this))->toBe($expected === [] ? [] : [$this->manager->id => $expected]);
})->with([
    '61 minutes before the unlock: offer' => ['2026-09-15 10:59', []],
    '59 minutes before the unlock: clause' => ['2026-09-15 11:01', ['sure' => 2_000_000, 'possible' => 0]],
]);

test('a round raise at a shield moment is certain', function (): void {
    acquire($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    valueOn($this, '2026-09-10', 5_432_100);
    valueOn($this, '2026-09-20', 6_000_000);
    valueOn($this, '2026-09-26', 6_543_210);
    Activity::factory()->create([
        'season_id' => $this->season->id, 'type' => SeasonActivityType::Shield,
        'source_season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
        'amount' => null, 'occurred_at' => '2026-09-20 21:00',
    ]);
    ownNow($this, $this->manager, 9_000_000);

    // Not round over the unlock clause (5.432.100) nor today's, but 3 M over the 6 M clause at the shield.
    expect(detect($this))->toBe([$this->manager->id => ['sure' => 3_000_000, 'possible' => 0]]);
});

test('Olasagasti: a round 10,5 M paid after the lock is the clause the owner raised, never an offer', function (): void {
    acquire($this, $this->manager, 3_840_476, '2026-08-09 20:00:26');
    foreach (['2026-08-09' => 3_840_476, '2026-08-16' => 4_382_766, '2026-08-23' => 3_955_739, '2026-09-02' => 4_777_195, '2026-09-09' => 7_339_780, '2026-09-14' => 8_959_646] as $date => $value) {
        valueOn($this, $date, $value);
    }
    foreach (['2026-09-02 21:07:40', '2026-09-09 21:00:30'] as $shieldedAt) {
        Activity::factory()->create([
            'season_id' => $this->season->id, 'type' => SeasonActivityType::Shield,
            'source_season_manager_id' => $this->manager->id, 'player_id' => $this->player->id,
            'amount' => null, 'occurred_at' => $shieldedAt,
        ]);
    }
    acquire($this, $this->rival, 10_500_000, '2026-09-14 07:26:00', SeasonActivityType::Buyout, $this->manager);

    // No anchor gives a round excess, so it is possible: 10,5 M over the clause at unlock (4.382.766).
    expect(detect($this))->toBe([$this->manager->id => ['sure' => 0, 'possible' => 6_117_234]]);
});

test('Marc Roca: 24.314.010 over the 17.614.010 value at unlock is a certain 6,7 M raise', function (): void {
    acquire($this, $this->manager, 12_680_723, '2026-09-12 20:03:18', SeasonActivityType::Buyout, $this->rival);
    valueOn($this, '2026-09-12', 11_680_919);
    valueOn($this, '2026-09-26', 17_614_010);
    valueOn($this, '2026-09-28', 18_900_000);
    ownNow($this, $this->manager, 24_314_010);

    expect(detect($this))->toBe([$this->manager->id => ['sure' => 6_700_000, 'possible' => 0]]);
});
