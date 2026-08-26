<?php

declare(strict_types=1);

use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
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

    $third = SeasonTeam::factory()->create(['season_id' => $season->id, 'position' => 3]);
    $first = SeasonTeam::factory()->create(['season_id' => $season->id, 'position' => 1]);
    $second = SeasonTeam::factory()->create(['season_id' => $season->id, 'position' => 2]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('standings', 3)
        ->where('standings.0.id', $first->id)
        ->where('standings.1.id', $second->id)
        ->where('standings.2.id', $third->id)
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
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDay()]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'points' => 9]);
    MarketPlayer::factory()->create(['player_id' => $player->id]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('market.0.player.recent_scores', [9, null, null])
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

    $mostRecent = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'occurred_at' => now(),
    ]);

    for ($i = 1; $i <= 10; $i++) {
        SeasonActivity::factory()->create([
            'season_id' => $season->id,
            'occurred_at' => now()->subHours($i),
        ]);
    }

    SeasonActivity::factory()->create([
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

test('shows the local and guest team names for each fixture', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $local = Team::factory()->create(['name' => 'Real Sociedad']);
    $guest = Team::factory()->create(['name' => 'Villarreal CF']);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $local->id,
        'team_guest_id' => $guest->id,
    ]);

    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('fixtures.0.local_team.name', 'Real Sociedad')
        ->where('fixtures.0.guest_team.name', 'Villarreal CF')
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

    $activity = SeasonActivity::factory()->create([
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
