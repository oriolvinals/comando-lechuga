<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerPosition;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

function tickerSeason(int $currentWeek = 5): Season
{
    return Season::factory()->create([
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'current_week' => $currentWeek,
    ]);
}

test('shares the teletipo with every inertia page and no live matches when none are being played', function (): void {
    $season = tickerSeason();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('ticker', fn (Assert $ticker): AssertableInertia => $ticker
            ->where('live', [])
            ->where('finished_week', 4)
            ->has('results')
            ->has('risers')
            ->has('fallers')
            ->has('activities')));
});

test('lists the matches being played with their score and clock', function (): void {
    $season = tickerSeason();
    $local = Team::factory()->create(['short_name' => 'BAR']);
    $guest = Team::factory()->create(['short_name' => 'RMA']);
    $live = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'team_local_id' => $local->id,
        'team_guest_id' => $guest->id,
        'local_score' => 2,
        'guest_score' => 1,
        'date' => now()->subMinutes(70),
        'state' => FixtureState::SecondHalf,
        'display_clock' => "67'",
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->subMinutes(50),
        'state' => FixtureState::HalfTime,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('ticker.live', 2)
        ->where('ticker.live.0.id', $live->id)
        ->where('ticker.live.0.state', 'second_half')
        ->where('ticker.live.0.display_clock', "67'")
        ->where('ticker.live.0.local_score', 2)
        ->where('ticker.live.0.guest_score', 1)
        ->where('ticker.live.0.local_team.short_name', 'BAR')
        ->where('ticker.live.0.guest_team.short_name', 'RMA')
        ->where('ticker.live.1.state', 'half_time')
        ->where('ticker.finished_week', 4));
});

test('shows the finished results of the last fully finished jornada in kickoff order', function (): void {
    $season = tickerSeason();
    $late = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->subDay(),
        'local_score' => 0,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    $early = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->subDays(2),
        'local_score' => 3,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 4,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('ticker.finished_week', 5)
        ->has('ticker.results', 2)
        ->where('ticker.results.0.id', $early->id)
        ->where('ticker.results.0.local_score', 3)
        ->where('ticker.results.1.id', $late->id));
});

test('falls back to the previous jornada while the current one is partly played', function (): void {
    $season = tickerSeason();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Finished,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Scheduled,
    ]);
    $previous = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 4,
        'state' => FixtureState::Finished,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 4,
        'state' => FixtureState::Postponed,
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('ticker.finished_week', 4)
        ->has('ticker.results', 1)
        ->where('ticker.results.0.id', $previous->id));
});

test('lists the top 4 risers and top 3 fallers of the latest market update, coaches excluded', function (): void {
    $season = tickerSeason();
    $moverDifferences = [
        'riser 1' => 900_000,
        'riser 2' => 700_000,
        'riser 3' => 500_000,
        'riser 4' => 300_000,
        'riser 5' => 100_000,
        'steady' => 0,
        'faller 3' => -200_000,
        'faller 2' => -400_000,
        'faller 1' => -800_000,
        'faller 4' => -100_000,
    ];

    foreach ($moverDifferences as $nickname => $difference) {
        Player::factory()->create([
            'nickname' => $nickname,
            'position' => PlayerPosition::Midfield,
            'market_value_difference' => $difference,
            'market_trend' => $difference > 0 ? MarketTrend::RiseSteady : null,
        ]);
    }

    Player::factory()->create([
        'nickname' => 'coach',
        'position' => PlayerPosition::Coach,
        'market_value_difference' => 5_000_000,
    ]);
    PlayerSeason::factory()->create([
        'player_id' => Player::factory()->create(['nickname' => 'other season', 'market_value_difference' => 0])->id,
        'position' => PlayerPosition::Striker,
        'market_value_difference' => 9_000_000,
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('ticker.risers', fn ($risers): bool => collect($risers)->pluck('nickname')->all() === ['riser 1', 'riser 2', 'riser 3', 'riser 4'])
        ->where('ticker.risers.0.market_value_difference', 900_000)
        ->where('ticker.risers.0.market_trend', 'rise_steady')
        ->where('ticker.fallers', fn ($fallers): bool => collect($fallers)->pluck('nickname')->all() === ['faller 1', 'faller 2', 'faller 3'])
        ->where('ticker.fallers.0.market_trend', null));
});

test('lists the 5 latest activities of the season, newest first', function (): void {
    $season = tickerSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Lechugas FC']);
    $player = Player::factory()->create(['nickname' => 'Pedri']);

    foreach (range(1, 6) as $hoursAgo) {
        Activity::factory()->create([
            'season_id' => $season->id,
            'source_season_manager_id' => $manager->id,
            'player_id' => $player->id,
            'type' => SeasonActivityType::Signing,
            'amount' => $hoursAgo * 1_000_000,
            'occurred_at' => now()->subHours($hoursAgo),
        ]);
    }

    $prize = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'player_id' => null,
        'type' => SeasonActivityType::WeeklyPrize,
        'amount' => 250_000,
        'week_number' => 4,
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('ticker.activities', 5)
        ->where('ticker.activities.0.id', $prize->id)
        ->where('ticker.activities.0.type', 'weekly_prize')
        ->where('ticker.activities.0.manager_name', 'Lechugas FC')
        ->where('ticker.activities.0.player_nickname', null)
        ->where('ticker.activities.1.player_nickname', 'Pedri')
        ->where('ticker.activities.1.amount', 1_000_000)
        ->where('ticker.activities.4.amount', 4_000_000));
});
