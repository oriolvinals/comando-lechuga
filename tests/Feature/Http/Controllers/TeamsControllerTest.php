<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

test('orders standings by points then goal difference', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $alpha = Team::factory()->create(['main_name' => 'Alpha FC']);
    $beta = Team::factory()->create(['main_name' => 'Beta FC']);
    $gamma = Team::factory()->create(['main_name' => 'Gamma FC']);
    $season->teams()->attach([$alpha->id, $beta->id, $gamma->id]);

    // Alpha beats Gamma 3-0: Alpha 3pts/+3GD, Gamma 0pts/-3GD.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $alpha->id,
        'team_guest_id' => $gamma->id,
        'local_score' => 3,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    // Beta beats Gamma 1-0: Beta 3pts/+1GD, Gamma another 0pts/-1GD (cumulative -4GD).
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $beta->id,
        'team_guest_id' => $gamma->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    // A scheduled (not finished) fixture must not affect the table at all.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'team_local_id' => $gamma->id,
        'team_guest_id' => $alpha->id,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $alpha->id) // 3pts, +3 GD
        ->where('standings.0.position', 1)
        ->where('standings.1.team.id', $beta->id) // 3pts, +1 GD — same points, worse GD
        ->where('standings.1.position', 2)
        ->where('standings.2.team.id', $gamma->id) // 0pts
        ->where('standings.2.position', 3)
        ->where('standings.2.played', 2)
        ->where('standings.2.goal_difference', -4)
    );
});

test('a team with no finished fixtures appears with every stat zeroed', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->where('standings.0.played', 0)
        ->where('standings.0.won', 0)
        ->where('standings.0.drawn', 0)
        ->where('standings.0.lost', 0)
        ->where('standings.0.goals_for', 0)
        ->where('standings.0.goals_against', 0)
        ->where('standings.0.goal_difference', 0)
        ->where('standings.0.points', 0)
    );
});

test('shows a team by id', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create(['main_name' => 'Rayo Vallecano']);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('team.id', $team->id)
        ->where('team.main_name', 'Rayo Vallecano')
    );
});

test('returns 404 for an unknown team', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $response = $this->get('/equipos/999999');

    $response->assertNotFound();
});

test('a live match counts toward the table, exposes live details, and stays out of recent form', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 1,
        'guest_score' => 0,
        'state' => FixtureState::FirstHalf,
    ]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->where('standings.0.played', 1)
        ->where('standings.0.points', 3)
        ->where('standings.0.recent_form', [])
        ->where('standings.0.next', null)
        ->where('standings.0.live.fixture_id', $fixture->id)
        ->where('standings.0.live.opponent.id', $rival->id)
        ->where('standings.0.live.score', '1-0')
        ->where('standings.0.live.result', 'win')
        // Same fixture, the other side: Barcelona-vs-Madrid style — both
        // teams see it as live, from their own perspective.
        ->where('standings.1.live.fixture_id', $fixture->id)
        ->where('standings.1.live.opponent.id', $team->id)
        ->where('standings.1.live.score', '0-1')
        ->where('standings.1.live.result', 'loss')
    );
});

test('a team not currently live shows its next scheduled fixture', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    // Explicit name so this team sorts first among three teams tied at
    // 0 points/0 GD/0 GF (the default tiebreak is team name ascending).
    $team = Team::factory()->create(['main_name' => 'AAA Test Team']);
    $rivalA = Team::factory()->create();
    $rivalB = Team::factory()->create();
    $season->teams()->attach([$team->id, $rivalA->id, $rivalB->id]);
    // Further out — must not win over the sooner one below.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $team->id,
        'team_guest_id' => $rivalB->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addDays(10),
    ]);
    $soon = Fixture::factory()->create([
        'season_id' => $season->id,
        'team_local_id' => $rivalA->id,
        'team_guest_id' => $team->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addDays(2),
    ]);

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->where('standings.0.live', null)
        ->where('standings.0.next.fixture_id', $soon->id)
        ->where('standings.0.next.opponent.id', $rivalA->id)
        ->where('standings.0.next.is_home', false)
    );
});

test('the ficha squad includes the club players and excludes out-of-league ones', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $season->teams()->attach([$team->id]);
    $keeper = Player::factory()->create([
        'team_id' => $team->id,
        'status' => PlayerStatus::Ok,
        'position' => PlayerPosition::Goalkeeper,
    ]);
    $outOfLeague = Player::factory()->create([
        'team_id' => $team->id,
        'status' => PlayerStatus::OutOfLeague,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('squad', 1)
        ->where('squad.0.id', $keeper->id)
    );
    expect($outOfLeague)->not->toBeNull(); // keeps the variable "used" for readability of intent
});

test('a recent score slot shows a match played for the player\'s previous club before a transfer', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $newClub = Team::factory()->create();
    $oldClub = Team::factory()->create();
    $season->teams()->attach([$newClub->id, $oldClub->id]);
    // Currently at $newClub, but their only recent finished match was played for
    // $oldClub before the transfer — $newClub itself hasn't played since.
    $player = Player::factory()->create([
        'team_id' => $newClub->id,
        'status' => PlayerStatus::Ok,
    ]);
    $oldClubMatch = Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->subDays(10),
        'team_local_id' => $oldClub->id,
        'state' => FixtureState::Finished,
    ]);
    FixtureLineup::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $oldClubMatch->id,
        'team_id' => $oldClub->id,
        'fantasy_points' => 6,
    ]);

    $response = $this->get(route('teams.show', $newClub));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('squad.0.recent_scores', [6, null, null])
        ->where('squad.0.recent_scores_opponents.0.id', $oldClubMatch->team_guest_id)
    );
});

test('a jornada the player\'s old club and new club both played in only takes one recent-score slot', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $newClub = Team::factory()->create();
    $oldClub = Team::factory()->create();
    $rivalWeek2 = Team::factory()->create();
    $rivalWeek3 = Team::factory()->create();
    $rivalNewClubWeek3 = Team::factory()->create();
    $rivalWeek4 = Team::factory()->create();
    $season->teams()->attach([$newClub->id, $oldClub->id, $rivalWeek2->id, $rivalWeek3->id, $rivalNewClubWeek3->id, $rivalWeek4->id]);

    $player = Player::factory()->create(['team_id' => $newClub->id, 'status' => PlayerStatus::Ok]);

    // Weeks 2 and 3: played for $oldClub, before the transfer.
    $week2 = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => now()->subDays(20), 'team_local_id' => $oldClub->id, 'team_guest_id' => $rivalWeek2->id, 'state' => FixtureState::Finished]);
    $week3OldClub = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => now()->subDays(12), 'team_local_id' => $rivalWeek3->id, 'team_guest_id' => $oldClub->id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $week2->id, 'team_id' => $oldClub->id, 'fantasy_points' => 0]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $week3OldClub->id, 'team_id' => $oldClub->id, 'fantasy_points' => 3]);

    // $newClub also played its own week 3 fixture, without the player (still at $oldClub) —
    // this must NOT also claim a slot; the player's real week-3 match above already did.
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => now()->subDays(11), 'team_local_id' => $newClub->id, 'team_guest_id' => $rivalNewClubWeek3->id, 'state' => FixtureState::Finished]);

    // Week 4: the transfer has happened, played for $newClub.
    $week4 = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 4, 'date' => now()->subDays(5), 'team_local_id' => $newClub->id, 'team_guest_id' => $rivalWeek4->id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $week4->id, 'team_id' => $newClub->id, 'fantasy_points' => 7]);

    $response = $this->get(route('teams.show', $newClub));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('squad.0.recent_scores', [0, 3, 7])
        ->where('squad.0.recent_scores_opponents.0.id', $rivalWeek2->id)
        ->where('squad.0.recent_scores_opponents.1.id', $rivalWeek3->id)
        ->where('squad.0.recent_scores_opponents.2.id', $rivalWeek4->id)
    );
});

test('the ficha exposes this team\'s own position in the real standings', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 2,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standing.position', 1)
        ->where('standing.points', 3)
        ->where('standing.played', 1)
    );
});

test('recent form holds only the last 4 finished results, newest first by date (not week_number)', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);

    // 5 finished matches, deliberately out of week_number order by date, to prove
    // date (not week_number) drives the ordering: a match with a HIGHER week_number
    // but an EARLIER date must still sort as older.
    // date-oldest -> date-newest: win, loss, draw, win, win. Only the last 4
    // by date should appear (dropping the oldest "win"), newest first: win, win, draw, loss.
    $matches = [
        ['week_number' => 5, 'date' => now()->subDays(10), 'local' => 2, 'guest' => 0], // oldest by date, dropped
        ['week_number' => 1, 'date' => now()->subDays(8), 'local' => 0, 'guest' => 1],  // loss
        ['week_number' => 2, 'date' => now()->subDays(6), 'local' => 1, 'guest' => 1],  // draw
        ['week_number' => 3, 'date' => now()->subDays(4), 'local' => 3, 'guest' => 0],  // win
        ['week_number' => 4, 'date' => now()->subDays(2), 'local' => 2, 'guest' => 1],  // win, newest
    ];
    $fixtureIds = [];

    foreach ($matches as $match) {
        $fixture = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => $match['week_number'],
            'team_local_id' => $team->id,
            'team_guest_id' => $rival->id,
            'local_score' => $match['local'],
            'guest_score' => $match['guest'],
            'state' => FixtureState::Finished,
            'date' => $match['date'],
        ]);
        $fixtureIds[$match['week_number']] = $fixture->id;
    }

    $response = $this->get(route('teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('standings.0.team.id', $team->id)
        ->has('standings.0.recent_form', 4)
        ->where('standings.0.recent_form.0.result', 'win')
        ->where('standings.0.recent_form.0.fixture_id', $fixtureIds[4])
        ->where('standings.0.recent_form.0.score', '2-1')
        ->where('standings.0.recent_form.0.opponent.id', $rival->id)
        ->where('standings.0.recent_form.1.result', 'win')
        ->where('standings.0.recent_form.1.fixture_id', $fixtureIds[3])
        ->where('standings.0.recent_form.2.result', 'draw')
        ->where('standings.0.recent_form.2.fixture_id', $fixtureIds[2])
        ->where('standings.0.recent_form.3.result', 'loss')
        ->where('standings.0.recent_form.3.fixture_id', $fixtureIds[1])
    );
});

test('the ficha calendar includes both played and upcoming fixtures, ordered by week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $future = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Scheduled,
    ]);
    $past = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $rival->id,
        'team_guest_id' => $team->id,
        'local_score' => 1,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('fixtures', 2)
        ->where('fixtures.0.id', $past->id)
        ->where('fixtures.1.id', $future->id)
    );
});

test('the pitch defaults to the latest jornada with a synced lineup, even ahead of season.current_week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'local_score' => 2,
        'guest_score' => 1,
        'state' => FixtureState::Finished,
    ]);
    $starter = Player::factory()->create([
        'team_id' => $team->id,
        'position' => PlayerPosition::Striker,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $starter->id,
        'team_id' => $team->id,
        'starter' => true,
        'fantasy_points' => 9,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('currentWeek', 3)
        ->has('weeklyLineups', 1)
        ->where('weeklyLineups.0.week_number', 3)
        ->has('weeklyLineups.0.players', 1)
        ->where('weeklyLineups.0.players.0.player.id', $starter->id)
        ->where('weeklyLineups.0.players.0.points', 9)
        ->where('weeklyLineups.0.players.0.position', 'striker')
    );
});

test('the pitch positions starters by their real match line, not the fantasy position bucket', function (): void {
    // A 4-2-3-1 has 4 outfield lines (defender/DM/AM/forward) — more than
    // the fantasy position column's 3 buckets (defender/midfield/striker)
    // can represent, since defensive and attacking midfielders both count
    // as plain "midfield" there.
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Finished,
    ]);

    $lineup = [
        'goalkeeper' => 'Goalkeeper',
        'defender_left' => 'Left Back',
        'defender_center_a' => 'Center Back',
        'defender_center_b' => 'Center Back',
        'defender_right' => 'Right Back',
        'dm_left' => 'Defensive Midfielder Left',
        'dm_right' => 'Defensive Midfielder Right',
        'am_left' => 'Attacking Midfielder Left',
        'am_center' => 'Attacking Midfielder Center',
        'am_right' => 'Attacking Midfielder Right',
        'forward' => 'Forward',
    ];

    $players = [];

    foreach ($lineup as $key => $matchPosition) {
        $player = Player::factory()->create(['team_id' => $team->id]);
        $players[$key] = $player;

        FixtureLineup::factory()->create([
            'fixture_id' => $fixture->id,
            'player_id' => $player->id,
            'team_id' => $team->id,
            'starter' => true,
            'position' => $matchPosition,
        ]);
    }

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) use ($players): Assert {
        $entries = collect($page->toArray()['props']['weeklyLineups'][0]['players']);
        $entryFor = fn (string $key): array => $entries
            ->firstWhere('player.id', $players[$key]->id);

        // Goalkeeper/defender/forward anchors, unaffected by how many
        // midfield lines the formation has. (JSON round-trips an integral
        // float back as a plain int.)
        expect($entryFor('goalkeeper')['pitch_top'])->toBe(6);
        expect($entryFor('defender_left')['pitch_top'])->toBe(28);
        expect($entryFor('forward')['pitch_top'])->toBe(74);

        // Two distinct midfield lines (DM, AM) split evenly between the
        // defender and forward anchors — DM sits closer to defense, AM
        // closer to attack, and both are still tagged "midfield" by the
        // fantasy position column.
        $dmTop = $entryFor('dm_left')['pitch_top'];
        $amTop = $entryFor('am_left')['pitch_top'];
        expect($dmTop)->toBeGreaterThan(28)->toBeLessThan($amTop);
        expect($amTop)->toBeLessThan(74);
        expect($entryFor('dm_right')['pitch_top'])->toBe($dmTop);
        expect($entryFor('am_center')['pitch_top'])->toBe($amTop);
        expect($entryFor('am_right')['pitch_top'])->toBe($amTop);

        // Left-to-right order within a line is respected.
        expect($entryFor('defender_left')['pitch_left'])
            ->toBeLessThan($entryFor('defender_center_a')['pitch_left']);
        expect($entryFor('am_left')['pitch_left'])
            ->toBeLessThan($entryFor('am_center')['pitch_left']);
        expect($entryFor('am_center')['pitch_left'])
            ->toBeLessThan($entryFor('am_right')['pitch_left']);

        return $page;
    });
});

test('a jornada with no synced starting XI is omitted from weeklyLineups', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('weeklyLineups', 0)
        ->where('currentWeek', 1)
    );
});

test('a bench player (starter=false) does not appear on the pitch', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Finished,
        'local_score' => 1,
        'guest_score' => 0,
    ]);
    $bench = Player::factory()->create(['team_id' => $team->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $bench->id,
        'team_id' => $team->id,
        'starter' => false,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page->has('weeklyLineups', 0));
});

test('next fixtures are padded to 3, only scheduled, ordered by date, and resolve opponent/is_home correctly', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();
    $rivalA = Team::factory()->create();
    $rivalB = Team::factory()->create();
    $season->teams()->attach([$team->id, $rivalA->id, $rivalB->id]);

    // Already finished — must NOT appear among next fixtures.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rivalA->id,
        'state' => FixtureState::Finished,
        'local_score' => 1,
        'guest_score' => 0,
        'date' => now()->subDays(3),
    ]);
    // Scheduled, team away — soonest.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'team_local_id' => $rivalA->id,
        'team_guest_id' => $team->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addDays(3),
    ]);
    // Scheduled, team home — later.
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'team_local_id' => $team->id,
        'team_guest_id' => $rivalB->id,
        'state' => FixtureState::Scheduled,
        'date' => now()->addDays(10),
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('nextFixtures.0.week_number', 2)
        ->where('nextFixtures.0.opponent.id', $rivalA->id)
        ->where('nextFixtures.0.is_home', false)
        ->where('nextFixtures.1.week_number', 3)
        ->where('nextFixtures.1.opponent.id', $rivalB->id)
        ->where('nextFixtures.1.is_home', true)
        ->where('nextFixtures.2', null)
    );
});

test('a starter with no resolved player is dropped from the pitch instead of crashing', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 1,
    ]);
    $team = Team::factory()->create();
    $rival = Team::factory()->create();
    $season->teams()->attach([$team->id, $rival->id]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $team->id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Finished,
        'local_score' => 1,
        'guest_score' => 0,
    ]);
    $resolved = Player::factory()->create([
        'team_id' => $team->id,
        'position' => PlayerPosition::Striker,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $resolved->id,
        'team_id' => $team->id,
        'starter' => true,
        'fantasy_points' => 7,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => null,
        'unresolved_name' => 'Jugador sin vincular',
        'team_id' => $team->id,
        'starter' => true,
        'fantasy_points' => null,
    ]);

    $response = $this->get(route('teams.show', $team));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): Assert => $page
        ->has('weeklyLineups.0.players', 1)
        ->where('weeklyLineups.0.players.0.player.id', $resolved->id)
    );
});
