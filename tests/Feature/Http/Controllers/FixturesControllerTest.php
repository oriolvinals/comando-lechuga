<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
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

test('includes lineups with pitch coordinates, event counts, points and dazn', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'state' => FixtureState::Finished]);
    $home = $fixture->localTeam;
    $player = Player::factory()->create(['team_id' => $home->id, 'position' => PlayerPosition::Defender]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'starter' => true,
        'position' => 'Left Back',
        'jersey' => '3',
        'stats' => [
            ['name' => 'totalGoals', 'value' => 1],
            ['name' => 'goalAssists', 'value' => 0],
            ['name' => 'yellowCards', 'value' => 1],
            ['name' => 'redCards', 'value' => 0],
        ],
    ]);
    PlayerScore::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'points' => 4,
        'stats' => ['marca_points' => [3, 0]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 1)
        ->where('lineups.0.player.id', $player->id)
        ->where('lineups.0.position', 'Left Back')
        ->where('lineups.0.jersey', '3')
        ->where('lineups.0.goals', 1)
        ->where('lineups.0.assists', 0)
        ->where('lineups.0.yellow_cards', 1)
        ->where('lineups.0.red_cards', 0)
        ->where('lineups.0.points', 4)
        ->where('lineups.0.dazn_points', 0)
        ->where('lineups.0.x', 20)
    );
});

test('includes the position from the fixture season for each lineup player', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id, 'position' => PlayerPosition::Defender]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $fixture->localTeam->id,
        'starter' => true,
        'position' => 'Left Back',
        'jersey' => '3',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 1)
        ->where('lineups.0.player.position', 'defender')
    );
});

test('populates dazn_points for a lineup entry while the fixture is live', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'state' => FixtureState::FirstHalf]);
    $home = $fixture->localTeam;
    $player = Player::factory()->create(['team_id' => $home->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);
    PlayerScore::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'points' => 4,
        'stats' => ['marca_points' => [3, 0]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.dazn_points', 0)
    );
});

test('nulls dazn_points for a lineup entry with no PlayerScore, regardless of fixture state', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'state' => FixtureState::FirstHalf]);
    $home = $fixture->localTeam;
    $player = Player::factory()->create(['team_id' => $home->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.dazn_points', null)
    );
});

test('includes a null player for an unresolved lineup entry, with no points/dazn', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => null,
        'team_id' => $fixture->localTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 1)
        ->where('lineups.0.player', null)
        ->where('lineups.0.points', null)
        ->where('lineups.0.dazn_points', null)
        ->where('lineups.0.x', 6)
    );
});

test('mirrors x coordinates for the guest team', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $fixture->guestTeam->id]),
        'team_id' => $fixture->guestTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.x', 94)
    );
});

test('includes events with the player relation, ordered by minute', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $scorer = Player::factory()->create(['team_id' => $fixture->localTeam->id]);

    FixtureEvent::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $fixture->localTeam->id, 'player_id' => $scorer->id, 'type' => 'goal', 'minute' => 73]);
    FixtureEvent::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $fixture->guestTeam->id, 'player_id' => null, 'type' => 'yellow_card', 'minute' => 12]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('events', 2)
        ->where('events.0.minute', 12)
        ->where('events.0.player', null)
        ->where('events.1.minute', 73)
        ->where('events.1.player.id', $scorer->id)
    );
});

test('sums fixture_lineups stats into team_stats by team', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $fixture->localTeam->id]),
        'team_id' => $fixture->localTeam->id,
        'stats' => [['name' => 'totalShots', 'value' => 4], ['name' => 'shotsOnTarget', 'value' => 2]],
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $fixture->guestTeam->id]),
        'team_id' => $fixture->guestTeam->id,
        'stats' => [['name' => 'totalShots', 'value' => 9]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('team_stats.1.label', 'Tiros totales')
        ->where('team_stats.1.local', 4)
        ->where('team_stats.1.guest', 9)
        ->where('team_stats.0.label', 'Tiros a puerta')
        ->where('team_stats.0.local', 2)
        ->where('team_stats.0.guest', 0)
    );
});

test('spreads multiple starters in the same pitch line by side then jersey', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $team = $fixture->localTeam;

    // Created out of the final pitch order on purpose: sort order is driven by
    // side (left/center/right) then jersey, not by insertion or position text.
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Left Back',
        'jersey' => '3',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Center Left Defender',
        'jersey' => '5',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Center Defender',
        'jersey' => '4',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Right Back',
        'jersey' => '2',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    // Expected order: Left Back (Left, jersey 3) -> Center Left Defender (Left,
    // jersey 5, tied on side, broken by jersey) -> Center Defender (Center) ->
    // Right Back (Right). y = 12 + index * (76 / 3), rounded to 1 decimal:
    // 12.0, 37.3, 62.7, 88.0 (whole-number floats serialize as bare ints over
    // the Inertia/JSON boundary, hence 12 and 88 below). This matches creation
    // order above, and the lineups list is returned in that same
    // (unordered-query / primary key) order.
    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 4)
        ->where('lineups.0.position', 'Left Back')
        ->where('lineups.0.y', 12)
        ->where('lineups.1.position', 'Center Left Defender')
        ->where('lineups.1.y', 37.3)
        ->where('lineups.2.position', 'Center Defender')
        ->where('lineups.2.y', 62.7)
        ->where('lineups.3.position', 'Right Back')
        ->where('lineups.3.y', 88)
    );
});

test('includes the fantasy manager who fielded a lineup player that jornada', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $fixture->localTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 3]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.lineup_manager.id', $seasonManager->id)
    );
});

test('has a null lineup_manager for a lineup player not fielded in any lineup that jornada', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $fixture->localTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.lineup_manager', null)
    );
});

test('has a null lineup_manager for an unresolved lineup entry', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => null,
        'team_id' => $fixture->localTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.lineup_manager', null)
    );
});
