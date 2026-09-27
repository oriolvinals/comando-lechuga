<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerPosition;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Player;
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
            ->where('market', [])
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

test('lists the current daily market listings soonest to expire first, expired listings and coaches excluded', function (): void {
    tickerSeason();
    $later = MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create([
            'nickname' => 'later',
            'position' => PlayerPosition::Midfield,
            'market_value_difference' => -300_000,
        ])->id,
        'expires_at' => now()->addHours(5),
        'value' => 8_000_000,
        'bids' => 0,
    ]);
    $sooner = MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create([
            'nickname' => 'sooner',
            'position' => PlayerPosition::Striker,
            'market_value_difference' => 450_000,
            'market_trend' => MarketTrend::RiseSteady,
        ])->id,
        'expires_at' => now()->addHours(2),
        'value' => 12_500_000,
        'bids' => 3,
    ]);
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create(['nickname' => 'expired', 'position' => PlayerPosition::Defender])->id,
        'expires_at' => now()->subMinute(),
    ]);
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create(['nickname' => 'coach', 'position' => PlayerPosition::Coach])->id,
        'expires_at' => now()->addHours(3),
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('ticker.market', fn ($market): bool => collect($market)->pluck('nickname')->all() === ['sooner', 'later'])
        ->where('ticker.market.0.id', $sooner->id)
        ->where('ticker.market.0.player_id', $sooner->player_id)
        ->where('ticker.market.0.value', 12_500_000)
        ->where('ticker.market.0.bids', 3)
        ->where('ticker.market.0.market_value_difference', 450_000)
        ->where('ticker.market.0.market_trend', 'rise_steady')
        ->where('ticker.market.1.id', $later->id)
        ->where('ticker.market.1.market_value_difference', -300_000));
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
