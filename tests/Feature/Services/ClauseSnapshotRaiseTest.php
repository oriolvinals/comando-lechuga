<?php

use App\Enums\ClauseSnapshotSource;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ClauseSnapshotRaise;
use Carbon\CarbonImmutable;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-02 20:00'));
    $this->season = Season::factory()->create(['start_date' => '2026-07-01', 'end_date' => '2027-06-30']);
    $this->manager = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $this->rival = SeasonManager::factory()->create(['season_id' => $this->season->id]);
    $this->player = Player::factory()->create();
});

function buy(object $test, SeasonManager $manager, int $amount, string $at): void
{
    Activity::factory()->create([
        'season_id' => $test->season->id, 'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id, 'player_id' => $test->player->id,
        'amount' => $amount, 'occurred_at' => $at,
    ]);
}

function syncRow(object $test, SeasonManager $manager, int $clause, int $value, string $at): void
{
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $manager->id, 'player_id' => $test->player->id, 'source' => ClauseSnapshotSource::Sync,
        'buyout_clause' => $clause, 'market_value' => $value, 'captured_at' => $at,
    ]);
}

function raiseFor(object $test, int $clause, int $value, string $at): int
{
    return app(ClauseSnapshotRaise::class)->forSync($test->manager->id, $test->player->id, $clause, $value, CarbonImmutable::parse($at));
}

test('a clause above the previous row and the value is the raise', function (): void {
    buy($this, $this->manager, 8_500_000, '2026-09-04 14:22');
    syncRow($this, $this->manager, 8_500_000, 4_889_987, '2026-09-30 10:00');

    expect(raiseFor($this, 9_500_000, 4_868_408, '2026-10-01 00:43'))->toBe(1_000_000);
});

test('a clause that only follows the value up is no raise', function (): void {
    buy($this, $this->manager, 9_000_000, '2026-09-04 14:22');
    syncRow($this, $this->manager, 9_206_505, 8_953_836, '2026-09-30 10:00');

    expect(raiseFor($this, 9_237_445, 9_237_445, '2026-10-01 00:43'))->toBe(0);
});

test('Rebbach: the first row of a holding is measured over the price paid and the highest value since', function (): void {
    buy($this, $this->manager, 8_500_000, '2026-09-04 14:22:52');
    PlayerMarket::factory()->create(['player_id' => $this->player->id, 'date' => '2026-09-06', 'value' => 5_411_060]);

    expect(raiseFor($this, 9_500_000, 4_868_408, '2026-10-01 00:43:21'))->toBe(1_000_000);
});

test('a row of a previous holding is never compared with the new one', function (): void {
    buy($this, $this->manager, 5_000_000, '2026-09-01 12:00');
    syncRow($this, $this->manager, 5_000_000, 4_000_000, '2026-09-02 12:00');
    buy($this, $this->manager, 12_000_000, '2026-09-20 12:00');

    expect(raiseFor($this, 12_000_000, 4_000_000, '2026-09-20 12:01'))->toBe(0);
});

test('an initial-squad first row without market history is no raise', function (): void {
    expect(raiseFor($this, 5_000_000, 4_000_000, '2026-10-01 00:43'))->toBe(0);
});
