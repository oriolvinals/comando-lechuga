<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the fixtures index page with every fixture for the current season', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);
    $week1 = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    $week2 = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2]);
    Fixture::factory()->create(['season_id' => $otherSeason->id, 'week_number' => 1]);

    $response = $this->get(route('fixtures.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('fixtures/index')
        ->has('fixtures', 2)
        ->where('fixtures.0.id', $week1->id)
        ->where('fixtures.1.id', $week2->id)
    );
});

test('orders index fixtures by week number then by date', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $laterInWeek = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => now()->addDays(2)]);
    $earlierInWeek = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => now()->addDay()]);
    $nextWeek = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => now()]);

    $response = $this->get(route('fixtures.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('fixtures.0.id', $earlierInWeek->id)
        ->where('fixtures.1.id', $laterInWeek->id)
        ->where('fixtures.2.id', $nextWeek->id)
    );
});

test('includes week progress and season on the fixtures index page', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
        'total_weeks' => 38,
    ]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Finished]);

    $response = $this->get(route('fixtures.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('season.current_week', 2)
        ->where('season.total_weeks', 38)
        ->where('weekProgress.1', 'all')
    );
});

test('renders the fixture show page', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('fixtures/show')
        ->where('fixture.id', $fixture->id)
    );
});

test('shows the other fixtures from the same week and season', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => now()->addDay()]);
    $sameWeek = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => now()->addDays(2)]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 4]);
    Fixture::factory()->create(['season_id' => $otherSeason->id, 'week_number' => 3]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('weekFixtures', 2)
        ->where('weekFixtures.0.id', $fixture->id)
        ->where('weekFixtures.1.id', $sameWeek->id)
    );
});

test('orders scores by position (goalkeeper, defender, midfield, striker) then by points', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create();

    $striker = Player::factory()->create(['position' => PlayerPosition::Striker]);
    $goalkeeper = Player::factory()->create(['position' => PlayerPosition::Goalkeeper]);
    $midfield = Player::factory()->create(['position' => PlayerPosition::Midfield]);
    $defender = Player::factory()->create(['position' => PlayerPosition::Defender]);

    $strikerScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $striker->id, 'points' => 3]);
    $goalkeeperScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $goalkeeper->id, 'points' => 1]);
    $midfieldScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $midfield->id, 'points' => 9]);
    $defenderScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $team->id, 'player_id' => $defender->id, 'points' => 5]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('scores', 4)
        ->where('scores.0.id', $goalkeeperScore->id)
        ->where('scores.1.id', $defenderScore->id)
        ->where('scores.2.id', $midfieldScore->id)
        ->where('scores.3.id', $strikerScore->id)
    );
});

test('includes the position from the fixture season for each scoring player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['position' => PlayerPosition::Goalkeeper]);
    PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'points' => 7]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('scores', 1)
        ->where('scores.0.player.position', 'goalkeeper')
    );
});

test('excludes coaches from the scores', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $coach = Player::factory()->create(['position' => PlayerPosition::Coach]);
    $striker = Player::factory()->create(['position' => PlayerPosition::Striker]);
    PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $coach->id]);
    $strikerScore = PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $striker->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('scores', 1)
        ->where('scores.0.id', $strikerScore->id)
    );
});

test('only includes scores for this fixture', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create();
    $otherFixture = Fixture::factory()->create();
    PlayerScore::factory()->create(['fixture_id' => $otherFixture->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('scores', 0));
});

test('includes the fantasy manager that fielded a player in their lineup that jornada', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3]);
    $player = Player::factory()->create(['position' => PlayerPosition::Striker]);
    PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 3]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager.id', $seasonManager->id)
    );
});

test('has no lineup manager for a player not fielded in any lineup that jornada', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['position' => PlayerPosition::Striker]);
    PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager', null)
    );
});

test('excludes a lineup from a different week for the same player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3]);
    $player = Player::factory()->create(['position' => PlayerPosition::Striker]);
    PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherWeekLineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 4]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $otherWeekLineup->id, 'player_id' => $player->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager', null)
    );
});
