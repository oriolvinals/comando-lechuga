<?php

use App\Console\Commands\BacktestMaxBid;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;

test('replays the model over the market history and reports how it did', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $riser = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $faller = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    foreach (range(0, 24) as $day) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString();
        PlayerMarket::factory()->create(['player_id' => $riser->id, 'date' => $date, 'value' => 10_000_000 + $day * 200_000]);
        PlayerMarket::factory()->create(['player_id' => $faller->id, 'date' => $date, 'value' => 10_000_000 - $day * 200_000]);
    }

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-04', '--to' => '2026-09-10'])
        ->expectsOutputToContain('Estimaciones')
        ->expectsOutputToContain('Total')
        ->assertSuccessful();

    expect(PlayerMarket::query()->count())->toBe(50);
});

test('fails clearly when there is no market history to replay', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);

    $this->artisan(BacktestMaxBid::class)
        ->expectsOutputToContain('No hay histórico de mercado')
        ->assertFailed();
});

test('replays one player day by day when --player is given', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $riser = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $faller = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    foreach (range(0, 24) as $day) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString();
        PlayerMarket::factory()->create(['player_id' => $riser->id, 'date' => $date, 'value' => 10_000_000 + $day * 200_000]);
        PlayerMarket::factory()->create(['player_id' => $faller->id, 'date' => $date, 'value' => 10_000_000 - $day * 200_000]);
    }

    $this->artisan(BacktestMaxBid::class, [
        '--from' => '2026-09-04',
        '--to' => '2026-09-10',
        '--player' => $riser->nickname,
    ])
        ->expectsOutputToContain('Estimaciones')
        ->expectsOutputToContain('2026-09-04')
        ->expectsOutputToContain('2026-09-10')
        ->assertSuccessful();
});

test('fails clearly for an unknown player nickname', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-09-20', 'value' => 10_000_000]);

    $this->artisan(BacktestMaxBid::class, ['--player' => 'Nadie De Nadie'])
        ->expectsOutputToContain('Nadie De Nadie')
        ->assertFailed();
});
