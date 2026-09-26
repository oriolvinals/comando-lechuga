<?php

use App\Enums\MaxBidStatus;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\MaxBidCalculator;

beforeEach(function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);

    // A big flat "rest of the market", so the market index stays ~0 and the
    // player under test is measured on his own momentum.
    maxBidPlayer($this->season, array_fill(0, 10, 1_000_000_000));
});

/**
 * A current-season player with one market value per day, the last one today.
 *
 * @param  list<int>  $values  oldest first
 * @param  array<string, mixed>  $attributes
 */
function maxBidPlayer(Season $season, array $values, array $attributes = []): Player
{
    $team = isset($attributes['team_id']) ? Team::query()->findOrFail($attributes['team_id']) : Team::factory()->create();
    $season->teams()->syncWithoutDetaching([$team->id]);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, ...$attributes]);

    foreach (array_values($values) as $index => $value) {
        PlayerMarket::factory()->create([
            'player_id' => $player->id,
            'date' => now()->subDays(count($values) - 1 - $index)->toDateString(),
            'value' => $value,
        ]);
    }

    return $player;
}

test('a rising player is profitable with a bid above his value', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Profitable)
        ->and($estimate->value)->toBe(10_300_000)
        ->and($estimate->momentumIncrement)->toEqualWithDelta(100_000, 0.01)
        ->and($estimate->projection)->toHaveCount(15)
        ->and($estimate->bid)->toBeGreaterThan(10_300_000);
});

test('a falling player is unprofitable even though a lucky offer could beat his value', function (): void {
    $player = maxBidPlayer($this->season, [10_300_000, 10_200_000, 10_100_000, 10_000_000]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Unprofitable)
        ->and($estimate->bid)->toBeNull()
        ->and($estimate->projection[14])->toBeLessThan(10_000_000);
});

test('an injured, suspended or out-of-league player is unavailable', function (PlayerStatus $status): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000], ['status' => $status]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Unavailable)
        ->and($estimate->value)->toBe(10_300_000)
        ->and($estimate->bid)->toBeNull();
})->with([PlayerStatus::Injured, PlayerStatus::Suspended, PlayerStatus::OutOfLeague]);

test('fewer than four days of market history is not enough data', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000]);

    expect(app(MaxBidCalculator::class)->estimate($player, $this->season)->status)->toBe(MaxBidStatus::NoData);
});

test('uses the last four available values when a day is missing', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000]);
    PlayerMarket::query()->where('player_id', $player->id)->whereDate('date', now()->subDays(2))->delete();

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->status)->toBe(MaxBidStatus::Profitable)
        ->and($estimate->momentumIncrement)->toEqualWithDelta((10_400_000 - 10_000_000) / 3, 0.01);
});

test('the market index is neutral when the start of the window has no data', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 10_400_000]);
    // No player at all has a value 3 days ago — as in the first days of a season.
    PlayerMarket::query()->whereDate('date', now()->subDays(3))->delete();

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season);

    expect($estimate->marketAdjustment)->toBe(0.0);
});

test('the reference date ignores anything after it', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000, 9_000_000, 8_000_000]);

    $estimate = app(MaxBidCalculator::class)->estimate($player, $this->season, now()->subDays(2));

    expect($estimate->value)->toBe(10_300_000)
        ->and($estimate->status)->toBe(MaxBidStatus::Profitable);
});

test('serializes for the ficha', function (): void {
    $player = maxBidPlayer($this->season, [10_000_000, 10_100_000, 10_200_000, 10_300_000]);

    $array = app(MaxBidCalculator::class)->estimate($player, $this->season)->toArray();

    expect($array['status'])->toBe('profitable')
        ->and($array['projected_day14'])->toBe($array['projection'][14])
        ->and($array['bid_premium'])->toBeGreaterThan(0);
});
