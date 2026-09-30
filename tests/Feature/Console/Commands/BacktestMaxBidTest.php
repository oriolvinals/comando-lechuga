<?php

use App\Console\Commands\BacktestMaxBid;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\ValueForecast\ValueForecastParameters;
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

test('fails clearly when several players share a nickname', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $teamA = Team::factory()->create(['main_name' => 'Equipo A']);
    $teamB = Team::factory()->create(['main_name' => 'Equipo B']);
    $season->teams()->attach([$teamA->id, $teamB->id]);
    $playerA = Player::factory()->create(['team_id' => $teamA->id, 'status' => PlayerStatus::Ok, 'nickname' => 'Ambiguo']);
    $playerB = Player::factory()->create(['team_id' => $teamB->id, 'status' => PlayerStatus::Ok, 'nickname' => 'Ambiguo']);
    PlayerMarket::factory()->create(['player_id' => $playerA->id, 'date' => '2026-09-20', 'value' => 10_000_000]);

    $this->artisan(BacktestMaxBid::class, ['--player' => 'Ambiguo'])
        ->expectsOutputToContain("#{$playerA->id} (Equipo A), #{$playerB->id} (Equipo B)")
        ->assertFailed();
});

test('classifies the market trend correctly no matter the market row insertion order', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $riser = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    // Insert newest first: without an explicit ORDER BY when reading them back,
    // this reproduces the un-ordered history bug the fix targets.
    foreach (array_reverse(range(0, 24)) as $day) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString();
        PlayerMarket::factory()->create(['player_id' => $riser->id, 'date' => $date, 'value' => 10_000_000 + $day * 200_000]);
    }

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-10', '--to' => '2026-09-10'])
        ->expectsOutputToContain('tendencia: rise_steady')
        ->assertSuccessful();
});

test('grid-searches the model parameters in memory, printing the defaults row and writing nothing', function (): void {
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

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-04', '--to' => '2026-09-10', '--grid' => true])
        ->expectsOutputToContain('Pasada 1')
        ->expectsOutputToContain('Pasada 2')
        ->expectsOutputToContain('| Peso rivales | Peso titular. |')
        ->expectsOutputToContain('defaults')
        ->expectsOutputToContain('tendencia: rise_steady')
        ->assertSuccessful();

    expect(PlayerMarket::query()->count())->toBe(50)
        ->and(Player::query()->count())->toBe(2);
});

test('the grid can be restricted to the matchweek phase', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $riser = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    foreach (range(0, 24) as $day) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString();
        PlayerMarket::factory()->create(['player_id' => $riser->id, 'date' => $date, 'value' => 10_000_000 + $day * 200_000]);
    }

    // No fixtures at all: every day is a break, so a matchweek-only grid has nothing to replay.
    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-04', '--to' => '2026-09-10', '--grid' => true, '--phase' => 'matchweek'])
        ->expectsOutputToContain('No hay estimaciones')
        ->assertSuccessful();

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-04', '--to' => '2026-09-10', '--grid' => true, '--phase' => 'break'])
        ->expectsOutputToContain('defaults')
        ->assertSuccessful();
});

test('fails clearly for an unknown phase', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);

    $this->artisan(BacktestMaxBid::class, ['--grid' => true, '--phase' => 'siesta'])
        ->expectsOutputToContain('siesta')
        ->assertFailed();
});

test('grid-searches only the low decays around the chosen calibration with --grid-decay', function (): void {
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

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-04', '--to' => '2026-09-10', '--grid-decay' => true])
        ->expectsOutputToContain('Pasada 3')
        ->doesntExpectOutputToContain('Pasada 1')
        ->expectsOutputToContain('defaults')
        ->expectsOutputToContain('0,65')
        ->expectsOutputToContain('Desglose de la ganadora de la pasada 3')
        ->assertSuccessful();

    expect(PlayerMarket::query()->count())->toBe(50);
});

test('grid-searches the streak exception with --grid-streak and reports a control player', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $riser = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok, 'nickname' => 'Racha']);
    $faller = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    foreach (range(0, 24) as $day) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString();
        PlayerMarket::factory()->create(['player_id' => $riser->id, 'date' => $date, 'value' => 10_000_000 + $day * 200_000]);
        PlayerMarket::factory()->create(['player_id' => $faller->id, 'date' => $date, 'value' => 10_000_000 - $day * 200_000]);
    }

    $this->artisan(BacktestMaxBid::class, [
        '--from' => '2026-09-04', '--to' => '2026-09-10', '--grid-streak' => true,
        '--control' => 'Racha', '--control-from' => '2026-09-05', '--control-to' => '2026-09-08',
    ])
        ->expectsOutputToContain('Pasada 4')
        ->doesntExpectOutputToContain('Pasada 1')
        ->expectsOutputToContain('Desglose de la ganadora de la pasada 4')
        ->expectsOutputToContain('Control (cualitativo, no puntúa): Racha, 2026-09-05 a 2026-09-08')
        ->assertSuccessful();

    expect(PlayerMarket::query()->count())->toBe(50);
});

test('fails clearly for a control player who is not replayed', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $player = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-09-20', 'value' => 10_000_000]);

    $this->artisan(BacktestMaxBid::class, ['--grid-streak' => true, '--control' => 'Nadie De Nadie'])
        ->expectsOutputToContain('Nadie De Nadie')
        ->assertFailed();
});

test('uses the walk-forward forecast as day 1 unless told not to, and reports the error against the ideal bid', function (): void {
    $this->travelTo('2026-09-26 12:00:00');
    $season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    app()->instance(ValueForecastParameters::class, new ValueForecastParameters(warmupDays: 0, minimumTrainingRows: 10));
    $team = Team::factory()->create();
    $season->teams()->attach($team);
    $riser = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $faller = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);

    foreach (range(0, 24) as $day) {
        $date = CarbonImmutable::parse('2026-09-01')->addDays($day)->toDateString();
        PlayerMarket::factory()->create(['player_id' => $riser->id, 'date' => $date, 'value' => 10_000_000 + $day * 200_000]);
        PlayerMarket::factory()->create(['player_id' => $faller->id, 'date' => $date, 'value' => 10_000_000 - $day * 200_000]);
    }

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-10', '--to' => '2026-09-10'])
        ->expectsOutputToContain('Previsión día 1: 2 de 2 estimaciones')
        ->expectsOutputToContain('Error de la puja frente a la ideal')
        ->assertSuccessful();

    $this->artisan(BacktestMaxBid::class, ['--from' => '2026-09-10', '--to' => '2026-09-10', '--without-forecast' => true])
        ->expectsOutputToContain('Previsión día 1: desactivada')
        ->assertSuccessful();
});
