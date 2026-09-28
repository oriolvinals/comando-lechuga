<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\MarketTrend;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the home page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->component('home'));
});

test('shows the fixtures for the requested week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
        'total_weeks' => 38,
    ]);

    $weekFiveFixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 6,
    ]);

    $response = $this->get(route('home', ['week' => 5]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('home')
        ->where('filters.week', 5)
        ->has('fixtures', 1)
        ->where('fixtures.0.id', $weekFiveFixture->id)
    );
});

test('defaults to the season current week when no week is given', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 7,
        'total_weeks' => 38,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('filters.week', 7));
});

test('clamps the requested week between 1 and the total number of weeks', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
        'total_weeks' => 38,
    ]);

    $tooHigh = $this->get(route('home', ['week' => 999]));
    $tooHigh->assertInertia(fn (Assert $page): AssertableInertia => $page->where('filters.week', 38));

    $tooLow = $this->get(route('home', ['week' => 0]));
    $tooLow->assertInertia(fn (Assert $page): AssertableInertia => $page->where('filters.week', 1));
});

test('shows the standings ordered by position', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $third = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 3]);
    $first = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);
    $second = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 2]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('standings', 3)
        ->where('standings.0.id', $first->id)
        ->where('standings.1.id', $second->id)
        ->where('standings.2.id', $third->id)
    );
});

test('shows how much each manager squad gained or lost in the latest daily market update', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);
    SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 2]);

    foreach ([250_000, -100_000] as $difference) {
        ManagerPlayer::factory()->create([
            'season_manager_id' => $manager->id,
            'player_id' => Player::factory()->create(['market_value_difference' => $difference])->id,
        ]);
    }

    $response = $this->get(route('home'));

    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('standings.0.daily_value_difference', 150_000)
        ->where('standings.1.daily_value_difference', 0)
    );
});

test('shows all current market players ordered by soonest to expire', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $expiresLater = MarketPlayer::factory()->create([
        'expires_at' => now()->addHours(20),
        'player_id' => Player::factory()->create([
            'position' => PlayerPosition::Striker,
        ])->id,
    ]);
    $expiresSoon = MarketPlayer::factory()->create([
        'expires_at' => now()->addHours(2),
        'player_id' => Player::factory()->create([
            'position' => PlayerPosition::Striker,
        ])->id,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('market', 2)
        ->where('market.0.id', $expiresSoon->id)
        ->where('market.1.id', $expiresLater->id)
    );
});

test('excludes market listings that have already expired', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $active = MarketPlayer::factory()->create([
        'expires_at' => now()->addHours(2),
        'player_id' => Player::factory()->create([
            'position' => PlayerPosition::Striker,
        ])->id,
    ]);
    MarketPlayer::factory()->create([
        'expires_at' => now()->subMinute(),
        'player_id' => Player::factory()->create([
            'position' => PlayerPosition::Striker,
        ])->id,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('market', 1)
        ->where('market.0.id', $active->id)
    );
});

test('includes recent scores for each market listing player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create(['position' => PlayerPosition::Striker]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDay(), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'fantasy_points' => 9]);
    MarketPlayer::factory()->create(['player_id' => $player->id]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('market.0.player.recent_scores', [9, null, null])
    );
});

test('includes the next start probability for each market listing player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create([
        'position' => PlayerPosition::Striker,
        'status' => PlayerStatus::Ok,
    ]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $player->team_id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'probability' => 82,
    ]);
    MarketPlayer::factory()->create(['player_id' => $player->id]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('market.0.player.next_start.fixture_id', $fixture->id)
        ->where('market.0.player.next_start.probability', 82)
    );
});

test('has no next start for a market listing player without data', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create([
            'position' => PlayerPosition::Striker,
        ])->id,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('market.0.player.next_start', null)
    );
});

test('includes the current season position, points and market value for each market listing player', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create([
        'position' => PlayerPosition::Midfield,
        'points' => 87,
        'market_value' => 12_000_000,
        'market_value_difference' => -350_000,
        'market_trend' => MarketTrend::FallAccelerating,
    ]);
    MarketPlayer::factory()->create(['player_id' => $player->id]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('market.0.player.position', 'midfield')
        ->where('market.0.player.points', 87)
        ->where('market.0.player.market_value', 12_000_000)
        ->where('market.0.player.market_value_difference', -350_000)
        ->where('market.0.player.market_trend', 'fall_accelerating')
    );
});

test('excludes coaches from the market listing', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $strikerListing = MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create([
            'position' => PlayerPosition::Striker,
        ])->id,
    ]);
    MarketPlayer::factory()->create([
        'player_id' => Player::factory()->create([
            'position' => PlayerPosition::Coach,
        ])->id,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('market', 1)
        ->where('market.0.id', $strikerListing->id)
    );
});

test('shows the 10 most recent activity entries in the current season, newest first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);

    $mostRecent = Activity::factory()->create([
        'season_id' => $season->id,
        'occurred_at' => now(),
    ]);

    for ($i = 1; $i <= 10; $i++) {
        Activity::factory()->create([
            'season_id' => $season->id,
            'occurred_at' => now()->subHours($i),
        ]);
    }

    Activity::factory()->create([
        'season_id' => $otherSeason->id,
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('activity', 10)
        ->where('activity.0.id', $mostRecent->id)
    );
});

test('shows the local and guest team main_name for each fixture', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $local = Team::factory()->create(['main_name' => 'Real Sociedad']);
    $guest = Team::factory()->create(['main_name' => 'Villarreal CF']);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $local->id,
        'team_guest_id' => $guest->id,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('fixtures.0.local_team.main_name', 'Real Sociedad')
        ->where('fixtures.0.guest_team.main_name', 'Villarreal CF')
    );
});

test('shows the difference between the amount paid and the market value on that date', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $activityDate = now()->subDays(3);

    PlayerMarket::factory()->create([
        'player_id' => $player->id,
        'date' => $activityDate->toDateString(),
        'value' => 450_000,
    ]);

    $activity = Activity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'amount' => 500_000,
        'occurred_at' => $activityDate,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('activity.0.id', $activity->id)
        ->where('activity.0.value_difference', 50_000)
    );
});

test('shows the season current week and total weeks for the week selector', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 12,
        'total_weeks' => 38,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('season.current_week', 12)
        ->where('season.total_weeks', 38)
    );
});

test('hides live_points when the current week has not kicked off yet', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Scheduled,
    ]);
    SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 17]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('standings.0.live_points', null)
    );
});

test('shows live_points once the current week has kicked off', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::FirstHalf,
    ]);
    SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 17]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('standings.0.live_points', 17)
    );
});

test('hides live_points once the current week has finished but the season has not advanced yet', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 5,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'state' => FixtureState::Finished,
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 17]);
    ManagerLineup::factory()->create([
        'season_manager_id' => $manager->id,
        'week_number' => 5,
        'points' => 17,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('standings.0.live_points', null)
        ->where('standings.0.recent_form', [17, null, null])
    );
});

test('shows the next scheduled kickoff of the season with both teams', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'current_week' => 5,
        'total_weeks' => 38,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 6,
        'date' => now()->addDays(3),
        'state' => FixtureState::Scheduled,
    ]);
    $soonest = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('home', ['week' => 12]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('nextFixture.id', $soonest->id)
        ->where('nextFixture.local_team.id', $soonest->team_local_id)
        ->where('nextFixture.guest_team.id', $soonest->team_guest_id)
    );
});

test('skips kicked-off and overdue fixtures when picking the next kickoff', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'current_week' => 5,
        'total_weeks' => 38,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->subHour(),
        'state' => FixtureState::FirstHalf,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'date' => now()->subWeeks(2),
        'state' => FixtureState::Scheduled,
    ]);
    $upcoming = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->addHours(2),
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('nextFixture.id', $upcoming->id)
    );
});

test('has no next kickoff once every fixture has been played', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->subDay(),
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('nextFixture', null)
    );
});

function jornadaSeason(int $currentWeek = 7): Season
{
    return Season::factory()->create([
        'start_date' => now()->subMonth(),
        'end_date' => now()->addMonth(),
        'current_week' => $currentWeek,
        'total_weeks' => 38,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function jornadaFixture(Season $season, array $attributes): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => $season->current_week,
        ...$attributes,
    ]);
}

/**
 * A manager's lineup for one week, with one entry per player.
 *
 * @param  list<array{player: Player, fixture_id?: int|null, points?: int|null, position?: PlayerPosition}>  $entries
 */
function jornadaLineup(SeasonManager $manager, int $week, array $entries): ManagerLineup
{
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => $week]);

    foreach ($entries as $entry) {
        ManagerLineupPlayer::factory()->create([
            'manager_lineup_id' => $lineup->id,
            'player_id' => $entry['player']->id,
            'fixture_id' => $entry['fixture_id'] ?? null,
            'points' => $entry['points'] ?? null,
            'position' => $entry['position'] ?? PlayerPosition::Midfield,
        ]);
    }

    return $lineup;
}

test('shows the first matches of a jornada that has not started, without managers', function (): void {
    $season = jornadaSeason(8);
    $fixtures = collect([4, 1, 3, 2])->map(fn (int $days): Fixture => jornadaFixture($season, [
        'date' => now()->addDays($days),
        'state' => FixtureState::Scheduled,
    ]));
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    jornadaLineup($manager, 8, [['player' => Player::factory()->create(['team_id' => $fixtures[1]->team_local_id])]]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('jornadaMatches.week', 8)
        ->where('jornadaMatches.status', 'not_started')
        ->has('jornadaMatches.matches', 3)
        ->where('jornadaMatches.matches.0.id', $fixtures[1]->id)
        ->where('jornadaMatches.matches.1.id', $fixtures[3]->id)
        ->where('jornadaMatches.matches.2.id', $fixtures[2]->id)
        ->where('jornadaMatches.matches.0.state', 'scheduled')
        ->where('jornadaMatches.matches.0.local_team.id', $fixtures[1]->team_local_id)
        ->where('jornadaMatches.matches.0.managers', null)
    );
});

test('shows the last finished match with final points and the next two with their lineup managers', function (): void {
    $season = jornadaSeason();
    jornadaFixture($season, ['date' => now()->subDays(2), 'state' => FixtureState::Finished, 'local_score' => 0, 'guest_score' => 0]);
    $lastFinished = jornadaFixture($season, ['date' => now()->subDay(), 'state' => FixtureState::Finished, 'local_score' => 2, 'guest_score' => 1]);
    $next = jornadaFixture($season, ['date' => now()->addHour(), 'state' => FixtureState::Scheduled]);
    $afterNext = jornadaFixture($season, ['date' => now()->addHours(3), 'state' => FixtureState::Scheduled]);
    jornadaFixture($season, ['date' => now()->addDay(), 'state' => FixtureState::Scheduled]);

    $scorer = Player::factory()->create(['team_id' => $lastFinished->team_local_id, 'nickname' => 'Scorer']);
    $unresolved = Player::factory()->create(['team_id' => $lastFinished->team_guest_id, 'nickname' => 'Unresolved']);
    $upcoming = Player::factory()->create(['team_id' => $next->team_local_id, 'nickname' => 'Upcoming']);
    $star = Player::factory()->create(['team_id' => $lastFinished->team_guest_id, 'nickname' => 'Star']);
    FixtureLineup::factory()->create(['fixture_id' => $lastFinished->id, 'player_id' => $scorer->id, 'fantasy_points' => 9]);
    FixtureLineup::factory()->create(['fixture_id' => $lastFinished->id, 'player_id' => $star->id, 'fantasy_points' => 15]);

    $alpha = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Alpha', 'primary_color' => '#ff0000']);
    $bravo = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Bravo']);
    $charlie = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Charlie']);
    jornadaLineup($alpha, 7, [
        ['player' => $unresolved, 'points' => 3],
        ['player' => $scorer, 'fixture_id' => $lastFinished->id, 'points' => 1],
        ['player' => $upcoming],
    ]);
    jornadaLineup($bravo, 7, [['player' => $star, 'fixture_id' => $lastFinished->id]]);
    // Another jornada's lineup and a squad don't count — only this jornada's lineups.
    jornadaLineup($charlie, 6, [['player' => $scorer]]);
    ManagerPlayer::factory()->create(['season_manager_id' => $charlie->id, 'player_id' => $upcoming->id]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('jornadaMatches.status', 'started')
        ->has('jornadaMatches.matches', 3)
        ->where('jornadaMatches.matches.0.id', $lastFinished->id)
        ->where('jornadaMatches.matches.0.local_score', 2)
        ->where('jornadaMatches.matches.1.id', $next->id)
        ->where('jornadaMatches.matches.2.id', $afterNext->id)
        ->has('jornadaMatches.matches.0.managers', 2)
        ->where('jornadaMatches.matches.0.managers.0.name', 'Bravo')
        ->where('jornadaMatches.matches.0.managers.0.points', 15)
        ->where('jornadaMatches.matches.0.managers.1.name', 'Alpha')
        ->where('jornadaMatches.matches.0.managers.1.primary_color', '#ff0000')
        ->where('jornadaMatches.matches.0.managers.1.points', 12)
        ->where('jornadaMatches.matches.0.managers.1.players.0.nickname', 'Scorer')
        ->where('jornadaMatches.matches.0.managers.1.players.0.points', 9)
        ->where('jornadaMatches.matches.0.managers.1.players.1.nickname', 'Unresolved')
        ->where('jornadaMatches.matches.0.managers.1.players.1.points', 3)
        ->has('jornadaMatches.matches.1.managers', 1)
        ->where('jornadaMatches.matches.1.managers.0.name', 'Alpha')
        ->where('jornadaMatches.matches.1.managers.0.points', null)
        ->where('jornadaMatches.matches.1.managers.0.players.0.nickname', 'Upcoming')
        ->where('jornadaMatches.matches.1.managers.0.players.0.points', null)
        ->where('jornadaMatches.matches.2.managers', [])
    );
});

test('shows only the matches being played, with live points, while the jornada is live', function (): void {
    $season = jornadaSeason();
    jornadaFixture($season, ['date' => now()->subDay(), 'state' => FixtureState::Finished]);
    $secondHalf = jornadaFixture($season, [
        'date' => now()->subMinutes(80),
        'state' => FixtureState::SecondHalf,
        'display_clock' => "67'",
        'local_score' => 2,
        'guest_score' => 0,
    ]);
    $halfTime = jornadaFixture($season, ['date' => now()->subMinutes(50), 'state' => FixtureState::HalfTime, 'local_score' => 1, 'guest_score' => 1]);
    jornadaFixture($season, ['date' => now()->addHour(), 'state' => FixtureState::Scheduled]);

    $striker = Player::factory()->create(['team_id' => $secondHalf->team_local_id]);
    $keeper = Player::factory()->create(['team_id' => $halfTime->team_guest_id]);
    FixtureLineup::factory()->create(['fixture_id' => $secondHalf->id, 'player_id' => $striker->id, 'fantasy_points' => 7]);
    FixtureLineup::factory()->create(['fixture_id' => $halfTime->id, 'player_id' => $keeper->id, 'fantasy_points' => -2]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    jornadaLineup($manager, 7, [
        ['player' => $striker, 'fixture_id' => $secondHalf->id, 'position' => PlayerPosition::Striker],
        ['player' => $keeper, 'fixture_id' => $halfTime->id, 'position' => PlayerPosition::Goalkeeper],
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('jornadaMatches.status', 'live')
        ->has('jornadaMatches.matches', 2)
        ->where('jornadaMatches.matches.0.id', $secondHalf->id)
        ->where('jornadaMatches.matches.0.state', 'second_half')
        ->where('jornadaMatches.matches.0.display_clock', "67'")
        ->where('jornadaMatches.matches.0.managers.0.id', $manager->id)
        ->where('jornadaMatches.matches.0.managers.0.points', 7)
        ->where('jornadaMatches.matches.0.managers.0.players.0.position', 'striker')
        ->where('jornadaMatches.matches.1.id', $halfTime->id)
        ->where('jornadaMatches.matches.1.state', 'half_time')
        ->where('jornadaMatches.matches.1.managers.0.points', -2)
    );
});

test('shows the last three results of a jornada that has finished', function (): void {
    $season = jornadaSeason();
    $fixtures = collect([4, 3, 2, 1])->map(fn (int $days): Fixture => jornadaFixture($season, [
        'date' => now()->subDays($days),
        'state' => FixtureState::Finished,
    ]));

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('jornadaMatches.status', 'finished')
        ->has('jornadaMatches.matches', 3)
        ->where('jornadaMatches.matches.0.id', $fixtures[1]->id)
        ->where('jornadaMatches.matches.2.id', $fixtures[3]->id)
        ->where('jornadaMatches.matches.2.managers', [])
    );
});
