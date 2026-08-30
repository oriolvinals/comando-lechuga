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
use App\Models\Season;
use App\Models\SeasonManager;
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

test('lineups prop is empty when no FixtureLineup rows are synced yet, with no scores fallback', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 0)
        ->missing('scores')
    );
});

test('includes lineups with pitch coordinates, points and dazn', function (): void {
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
        'fantasy_points' => 4,
        'fantasy_stats' => ['marca_points' => [3, 0]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 1)
        ->where('lineups.0.player.id', $player->id)
        ->where('lineups.0.position', 'Left Back')
        ->where('lineups.0.jersey', '3')
        ->where('lineups.0.points', 4)
        ->where('lineups.0.dazn_points', 0)
        ->where('lineups.0.x', 14)
    );
});

test('exposes fantasy_stats as the stats prop, the single source for lineup event badges', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $fixture->localTeam->id,
        'fantasy_stats' => ['goals' => [1, 5], 'penalty_won' => [1, 5]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.stats', ['goals' => [1, 5], 'penalty_won' => [1, 5]])
    );
});

test('falls back to worldcup26 stats, shaped like fantasy_stats, when there is no fantasy_stats', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $fixture->localTeam->id,
        'fantasy_stats' => null,
        'stats' => [
            ['name' => 'totalGoals', 'value' => 2],
            ['name' => 'yellowCards', 'value' => 1],
        ],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.stats.goals', [2, 0])
        ->where('lineups.0.stats.yellow_card', [1, 0])
        ->where('lineups.0.stats.red_card', [0, 0])
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

test('nulls dazn_points for a lineup entry while the fixture has not finished', function (): void {
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
        'fantasy_points' => 4,
        'fantasy_stats' => ['marca_points' => [3, 0]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.dazn_points', null)
    );
});

test('nulls dazn_points for a lineup entry with no fantasy_stats, regardless of fixture state', function (): void {
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

test('includes match_data_id on a lineup entry so an unresolved player can be looked up', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => null,
        'team_id' => $fixture->localTeam->id,
        'starter' => true,
        'position' => 'Goalkeeper',
        'jersey' => '1',
        'match_data_id' => 415742,
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.match_data_id', 415742)
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
    FixtureEvent::factory()->create(['fixture_id' => $fixture->id, 'team_id' => $fixture->guestTeam->id, 'player_id' => null, 'unresolved_name' => 'Unlinked Player', 'type' => 'yellow_card', 'minute' => 12]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('events', 2)
        ->where('events.0.minute', 12)
        ->where('events.0.player', null)
        ->where('events.0.unresolved_name', 'Unlinked Player')
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

    // Pitch y is driven by side (left/center/right) then jersey, independent
    // of array order: Left Back (Left, alone) -> Center Defender (Center,
    // jersey 4) and Center Left Defender (Center, jersey 5 — a directional
    // qualifier on a center-back does not make it wide, so it ties on
    // Center with Center Defender and is broken by jersey) -> Right Back
    // (Right, alone). y = 12 + index * (76 / 3), rounded to 1 decimal: 12.0,
    // 37.3, 62.7, 88.0 (whole-number floats serialize as bare ints over the
    // Inertia/JSON boundary, hence 12 and 88 below).
    //
    // The `lineups` array order itself is a different axis: all four are the
    // same position line (Defender), so it's driven entirely by jersey
    // ascending (2, 3, 4, 5) — Right Back, Left Back, Center Defender, Center
    // Left Defender — not by creation order or side.
    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 4)
        ->where('lineups.0.position', 'Right Back')
        ->where('lineups.0.y', 88)
        ->where('lineups.1.position', 'Left Back')
        ->where('lineups.1.y', 12)
        ->where('lineups.2.position', 'Center Defender')
        ->where('lineups.2.y', 37.3)
        ->where('lineups.3.position', 'Center Left Defender')
        ->where('lineups.3.y', 62.7)
    );
});

test('a genuinely wide full-back keeps the touchline slot even against a lower-jerseyed center-back with a directional qualifier', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $team = $fixture->localTeam;

    // Mirrors the real bug: Fran García (Left Back, #11) vs Natan (Center
    // Left Defender, #4) in the Levante-Betis match — the center-back's
    // lower jersey number must NOT win the wide-left slot.
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Center Left Defender',
        'jersey' => '4',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Left Back',
        'jersey' => '11',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Center Right Defender',
        'jersey' => '5',
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

    // side: Left Back=Left(alone) -> y=12. The two Center-* entries tie on
    // Center, broken by jersey (4 before 5) -> y=37.3, 62.7. Right Back=Right
    // (alone) -> y=88.
    //
    // The `lineups` array order is a different axis, unrelated to side: all
    // four are the same position line (Defender), so it's driven entirely by
    // jersey ascending (2, 4, 5, 11) — Right Back, Center Left Defender,
    // Center Right Defender, Left Back.
    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 4)
        ->where('lineups.0.position', 'Right Back')
        ->where('lineups.0.y', 88)
        ->where('lineups.1.position', 'Center Left Defender')
        ->where('lineups.1.y', 37.3)
        ->where('lineups.2.position', 'Center Right Defender')
        ->where('lineups.2.y', 62.7)
        ->where('lineups.3.position', 'Left Back')
        ->where('lineups.3.y', 12)
    );
});

test('orders lineup entries by position line then jersey', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $team = $fixture->localTeam;

    // Created out of the final expected order on purpose.
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => false,
        'position' => 'Forward',
        'jersey' => '9',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => false,
        'position' => 'Goalkeeper',
        'jersey' => '13',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => false,
        'position' => 'Right Back',
        'jersey' => '2',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => false,
        'position' => 'Center Midfielder',
        'jersey' => '6',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 4)
        ->where('lineups.0.position', 'Goalkeeper')
        ->where('lineups.0.jersey', '13')
        ->where('lineups.1.position', 'Right Back')
        ->where('lineups.1.jersey', '2')
        ->where('lineups.2.position', 'Center Midfielder')
        ->where('lineups.2.jersey', '6')
        ->where('lineups.3.position', 'Forward')
        ->where('lineups.3.jersey', '9')
    );
});

test('uses a fixed, centered step for a 2-player pitch line instead of stretching to the edges', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $team = $fixture->localTeam;

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Left Forward',
        'jersey' => '9',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Right Forward',
        'jersey' => '11',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    // step = min(76/3, 76/1) = 76/3 ≈ 25.333, span = 25.333, start = 37.333.
    // Left (jersey 9) at 37.3, Right (jersey 11) at 62.7 — the same two
    // "inner" slots a 4-player line's middle two players already occupy,
    // not the far corners.
    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 2)
        ->where('lineups.0.position', 'Left Forward')
        ->where('lineups.0.y', 37.3)
        ->where('lineups.1.position', 'Right Forward')
        ->where('lineups.1.y', 62.7)
    );
});

test('uses a fixed, centered step for a 3-player pitch line', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $team = $fixture->localTeam;

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Left Midfielder',
        'jersey' => '6',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Center Midfielder',
        'jersey' => '8',
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => Player::factory()->create(['team_id' => $team->id]),
        'team_id' => $team->id,
        'starter' => true,
        'position' => 'Right Midfielder',
        'jersey' => '10',
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    // step = 25.333, span = 50.667, start = 24.667.
    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 3)
        ->where('lineups.0.position', 'Left Midfielder')
        ->where('lineups.0.y', 24.7)
        ->where('lineups.1.position', 'Center Midfielder')
        ->where('lineups.1.y', 50)
        ->where('lineups.2.position', 'Right Midfielder')
        ->where('lineups.2.y', 75.3)
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
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

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

test('lineup entries carry fantasy_points/fantasy_stats as points/stats, sourced from FixtureLineup directly', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $home = $fixture->localTeam;
    $player = Player::factory()->create(['team_id' => $home->id, 'position' => PlayerPosition::Defender]);

    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $home->id,
        'starter' => true,
        'position' => 'Left Back',
        'jersey' => '3',
        'fantasy_points' => 8,
        'fantasy_stats' => ['marca_points' => [3, 1]],
    ]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->missing('scores')
        ->where('lineups.0.points', 8)
        ->where('lineups.0.stats', ['marca_points' => [3, 1]])
    );
});

test('resolves lineup_manager via ManagerLineupPlayer.fixture_id, not week_number', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $fixture->localTeam->id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => $fixture->week_number]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.lineup_manager.id', $seasonManager->id)
    );
});

test('does not resolve lineup_manager for a ManagerLineupPlayer whose fixture_id points elsewhere', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id]);
    $otherFixture = Fixture::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['team_id' => $fixture->localTeam->id]);
    FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $player->id, 'team_id' => $fixture->localTeam->id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => $fixture->week_number]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $otherFixture->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.lineup_manager', null)
    );
});
