<?php

use App\Console\Commands\SnapshotPlayerSignals;
use App\Enums\DifficultyVariant;
use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineupProbability;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerDailySignal;
use App\Models\Season;
use App\Models\Team;
use App\Services\MatchDifficulty;

beforeEach(function (): void {
    $this->travelTo('2026-09-30 12:00:00');
    $this->season = Season::factory()->create(['start_date' => '2026-06-29', 'end_date' => '2027-05-31']);
    $this->team = Team::factory()->create();
    $this->rival = Team::factory()->create();
    $this->season->teams()->attach([$this->team->id, $this->rival->id]);
    $this->fixture = Fixture::factory()->create([
        'season_id' => $this->season->id,
        'team_local_id' => $this->team->id,
        'team_guest_id' => $this->rival->id,
        'date' => '2026-10-04 19:00:00',
        'state' => FixtureState::Scheduled,
    ]);
});

test('stores today\'s status, start probability, next rival difficulty and listing of each league player', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::Doubtful, 'position' => PlayerPosition::Striker]);
    FixtureLineupProbability::factory()->create(['player_id' => $player->id, 'fixture_id' => $this->fixture->id, 'probability' => 70, 'predicted_starter' => true]);
    MarketPlayer::factory()->create(['player_id' => $player->id]);

    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    $signal = PlayerDailySignal::query()->sole();
    $expectedDifficulty = app(MatchDifficulty::class)->forMany([[$this->fixture, $this->team->id, DifficultyVariant::Attack]])[0]?->difficulty;

    expect($signal->player_id)->toBe($player->id)
        ->and($signal->season_id)->toBe($this->season->id)
        ->and($signal->date->toDateString())->toBe('2026-09-30')
        ->and($signal->status)->toBe(PlayerStatus::Doubtful)
        ->and($signal->next_fixture_id)->toBe($this->fixture->id)
        ->and($signal->start_probability)->toBe(70)
        ->and($signal->predicted_starter)->toBeTrue()
        ->and($signal->confirmed_starter)->toBeNull()
        ->and($signal->next_difficulty)->toBe($expectedDifficulty)
        ->and($signal->listed)->toBeTrue();
});

test('a second run the same day overwrites the row and a new day adds one', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::Ok]);

    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();
    $player->update(['status' => PlayerStatus::Injured]);
    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    expect(PlayerDailySignal::query()->count())->toBe(1)
        ->and(PlayerDailySignal::query()->sole()->status)->toBe(PlayerStatus::Injured);

    $this->travelTo('2026-10-01 09:00:00');
    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    expect(PlayerDailySignal::query()->count())->toBe(2);
});

test('skips out-of-league players and players of other teams, and works without a next match', function (): void {
    $this->fixture->delete();
    $kept = Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::Ok]);
    Player::factory()->create(['team_id' => $this->team->id, 'status' => PlayerStatus::OutOfLeague]);
    Player::factory()->create(['team_id' => Team::factory()->create()->id, 'status' => PlayerStatus::Ok]);

    $this->artisan(SnapshotPlayerSignals::class)->assertSuccessful();

    $signal = PlayerDailySignal::query()->sole();
    expect($signal->player_id)->toBe($kept->id)
        ->and($signal->next_fixture_id)->toBeNull()
        ->and($signal->start_probability)->toBeNull()
        ->and($signal->next_difficulty)->toBeNull()
        ->and($signal->listed)->toBeFalse();
});
