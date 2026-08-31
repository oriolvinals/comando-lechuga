<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
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

test('renders the players index page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->component('players/index'));
});

test('paginates players, 15 per page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->count(20)->create(['status' => PlayerStatus::Ok]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.total', 20)
        ->where('players.per_page', 15)
        ->has('players.data', 15)
    );
});

test('searches players by nickname', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $match = Player::factory()->create(['status' => PlayerStatus::Ok, 'nickname' => 'Lamine Yamal']);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'nickname' => 'Pedri']);

    $response = $this->get(route('players.index', ['search' => 'yamal']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $match->id)
    );
});

test('searches players by nickname ignoring accents', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $match = Player::factory()->create(['status' => PlayerStatus::Ok, 'nickname' => 'Óscar Valentín']);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'nickname' => 'Pedri']);

    $response = $this->get(route('players.index', ['search' => 'valentin']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $match->id)
    );
});

test('filters players by several positions at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Goalkeeper]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Striker]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'position' => PlayerPosition::Midfield]);

    $response = $this->get(route('players.index', ['position' => 'goalkeeper,striker']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('players.data', 2));
});

test('filters players by several teams at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $third = Team::factory()->create();

    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $first->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $second->id]);
    Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $third->id]);

    $response = $this->get(route('players.index', ['team' => "{$first->id},{$second->id}"]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('players.data', 2));
});

test('filters players by fantasy season manager', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $ownerManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $owned = Player::factory()->create(['status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create(['season_manager_id' => $ownerManager->id, 'player_id' => $owned->id]);

    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->get(route('players.index', ['season_manager' => (string) $ownerManager->id]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $owned->id)
    );
});

test('lists fantasy season managers for the current season only, for the manager filter', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);

    $currentManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    SeasonManager::factory()->create(['season_id' => $otherSeason->id]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('seasonManagers', 1)
        ->where('seasonManagers.0.id', $currentManager->id)
    );
});

test('filters players by several statuses at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->create(['status' => PlayerStatus::Injured]);
    Player::factory()->create(['status' => PlayerStatus::Suspended]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->get(route('players.index', ['status' => 'injured,suspended']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('players.data', 2));
});

test('excludes out of league players from the list even without a status filter', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->create(['status' => PlayerStatus::Ok]);
    Player::factory()->create(['status' => PlayerStatus::OutOfLeague]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('players.data', 1)
        ->where('players.data.0.status', 'ok')
    );
});

test('excludes out of league players even when explicitly requested via the status filter', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->create(['status' => PlayerStatus::OutOfLeague]);

    $response = $this->get(route('players.index', ['status' => 'out_of_league']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('players.data', 0));
});

test('sorts players by points descending by default', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $low = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 10]);
    $high = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 90]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.id', $high->id)
        ->where('players.data.1.id', $low->id)
    );
});

test('sorts players by market value when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $cheap = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 500_000, 'points' => 90]);
    $expensive = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value' => 5_000_000, 'points' => 10]);

    $response = $this->get(route('players.index', ['sort' => 'value']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.id', $expensive->id)
        ->where('players.data.1.id', $cheap->id)
    );
});

test('sorts players by market value difference when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $dropped = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value_difference' => -50_000]);
    $risen = Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value_difference' => 200_000]);

    $response = $this->get(route('players.index', ['sort' => 'difference']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.id', $risen->id)
        ->where('players.data.1.id', $dropped->id)
    );
});

test('reverses the sort direction when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $low = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 10]);
    $high = Player::factory()->create(['status' => PlayerStatus::Ok, 'points' => 90]);

    $response = $this->get(route('players.index', ['direction' => 'asc']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.id', $low->id)
        ->where('players.data.1.id', $high->id)
    );
});

test('shows the owning fantasy manager when a player is owned', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'name' => 'Ariobretxa',
        'primary_color' => '#c4ff3d',
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);

    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.owner_manager.id', $seasonManager->id)
        ->where('players.data.0.owner_manager.name', 'Ariobretxa')
        ->where('players.data.0.owner_manager.logo', $seasonManager->logo)
        ->where('players.data.0.owner_manager.primary_color', '#c4ff3d')
    );
});

test('shows no owner for a free player', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('players.data.0.owner_manager', null));
});

test('includes points for the last 3 played matches, ordered by fixture date oldest first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    // Week numbers deliberately out of date order, so the test actually exercises
    // sorting by fixture date rather than by week_number or insertion order.
    $earliest = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 4, 'date' => now()->subDays(30), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $middle = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => now()->subDays(20), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $latest = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 5, 'date' => now()->subDays(10), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $earliest->id, 'fantasy_points' => 7]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $middle->id, 'fantasy_points' => 2]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $latest->id, 'fantasy_points' => 11]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.recent_scores', [7, 2, 11])
    );
});

test('only takes the 3 most recent matches, dropping older ones', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $oldest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(40), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $second = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(30), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $third = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(20), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $fourth = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(10), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $oldest->id, 'fantasy_points' => 99]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $second->id, 'fantasy_points' => 3]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $third->id, 'fantasy_points' => 5]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fourth->id, 'fantasy_points' => 8]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.recent_scores', [3, 5, 8])
    );
});

test('pads with null at the end when a player has fewer than 3 matches of history', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDay(), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'fantasy_points' => 9]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.recent_scores', [9, null, null])
    );
});

test('marks a recent score slot as not called up when the match finished without a score', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $scored = Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->subDays(20),
        'team_local_id' => $player->team_id,
        'state' => FixtureState::Finished,
    ]);
    $notCalledUp = Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->subDays(10),
        'team_local_id' => $player->team_id,
        'state' => FixtureState::Finished,
    ]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $scored->id, 'fantasy_points' => 5]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.recent_scores', [5, null, null])
        ->where('players.data.0.recent_scores_finished', [true, true, false])
    );
});

test('shows the rival faced in each recent score, not the player\'s own team', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $rival = Team::factory()->create(['main_name' => 'Rival CF']);
    $fixtureAsLocal = Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->subDays(10),
        'team_local_id' => $player->team_id,
        'team_guest_id' => $rival->id,
        'state' => FixtureState::Finished,
    ]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixtureAsLocal->id, 'fantasy_points' => 5]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.recent_scores_opponents.0.id', $rival->id)
    );
});

test('excludes scores from a fixture in a different season for the recent scores', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $otherSeasonFixture = Fixture::factory()->create(['season_id' => $otherSeason->id]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $otherSeasonFixture->id, 'fantasy_points' => 9]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.recent_scores', [null, null, null])
    );
});

test('shows the next 3 scheduled fixtures for a player, soonest first, with opponent and home/away', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $rivalA = Team::factory()->create();
    $rivalB = Team::factory()->create();
    $soonest = Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->addDays(3),
        'week_number' => 5,
        'team_local_id' => $player->team_id,
        'team_guest_id' => $rivalA->id,
        'state' => FixtureState::Scheduled,
    ]);
    $later = Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->addDays(10),
        'week_number' => 6,
        'team_local_id' => $rivalB->id,
        'team_guest_id' => $player->team_id,
        'state' => FixtureState::Scheduled,
    ]);
    // Finished/live fixtures never count as "next".
    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->subDay(),
        'team_local_id' => $player->team_id,
        'state' => FixtureState::Finished,
    ]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('players.data.0.next_fixtures.0.week_number', 5)
        ->where('players.data.0.next_fixtures.0.opponent.id', $rivalA->id)
        ->where('players.data.0.next_fixtures.0.is_home', true)
        ->where('players.data.0.next_fixtures.1.week_number', 6)
        ->where('players.data.0.next_fixtures.1.opponent.id', $rivalB->id)
        ->where('players.data.0.next_fixtures.1.is_home', false)
        ->where('players.data.0.next_fixtures.2', null)
    );
});

test('gives an out-of-league player 3 null next_fixtures on their own ficha', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create([
        'status' => PlayerStatus::OutOfLeague,
        'fantasy_id' => 12345,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->addDays(3),
        'team_local_id' => $player->team_id,
        'state' => FixtureState::Scheduled,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('player.next_fixtures', [null, null, null])
    );
});

test('lists the real teams for the team filter', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Team::factory()->create(['main_name' => 'Villarreal CF']);
    Team::factory()->create(['main_name' => 'Athletic Club']);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('teams', 2)
        ->where('teams.0.main_name', 'Athletic Club')
        ->where('teams.1.main_name', 'Villarreal CF')
    );
});

test('echoes the current filters back', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();

    $response = $this->get(route('players.index', [
        'position' => 'goalkeeper,striker',
        'team' => "{$team->id}",
        'status' => 'injured',
        'search' => 'yamal',
        'sort' => 'value',
        'direction' => 'asc',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('filters.position', ['goalkeeper', 'striker'])
        ->where('filters.team', [$team->id])
        ->where('filters.status', ['injured'])
        ->where('filters.search', 'yamal')
        ->where('filters.sort', 'value')
        ->where('filters.direction', 'asc')
    );
});

test('renders the player show page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('players/show')
        ->where('player.id', $player->id)
    );
});

test('shows the owning manager and clause details when the player is owned', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Gauchitos F.C']);
    $player = Player::factory()->create();
    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
        'buyout_clause' => 4_272_558,
        'shielded' => false,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('owner.season_manager.name', 'Gauchitos F.C')
        ->where('owner.buyout_clause', 4_272_558)
        ->where('owner.shielded', false)
    );
});

test('shows no owner in the ficha for a free player', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('owner', null));
});

test('shows the market listing when the player is free and listed', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    MarketPlayer::factory()->create(['player_id' => $player->id, 'bids' => 2, 'sale_price' => 751_587]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('marketListing.bids', 2)
        ->where('marketListing.sale_price', 751_587)
    );
});

test('has no market listing when the player is not listed', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('marketListing', null));
});

test('orders the market value history by date ascending', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(1), 'value' => 2_000_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(3), 'value' => 1_500_000]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => now()->subDays(2), 'value' => 1_800_000]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('marketHistory', 3)
        ->where('marketHistory.0.value', 1_500_000)
        ->where('marketHistory.1.value', 1_800_000)
        ->where('marketHistory.2.value', 2_000_000)
    );
});

test('only includes market history for this player', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $otherPlayer = Player::factory()->create();
    PlayerMarket::factory()->create(['player_id' => $otherPlayer->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('marketHistory', 0));
});

test('orders the player scores by week number ascending', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $weekTwoFixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2]);
    $weekOneFixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    $weekTwoScore = FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $weekTwoFixture->id]);
    $weekOneScore = FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $weekOneFixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('scores', 2)
        ->where('scores.0.id', $weekOneScore->id)
        ->where('scores.1.id', $weekTwoScore->id)
    );
});

test('only includes scores for this player', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $otherPlayer = Player::factory()->create();
    FixtureLineup::factory()->create(['player_id' => $otherPlayer->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('scores', 0));
});

test('includes the fantasy manager that fielded the player in their lineup that week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager.id', $seasonManager->id)
    );
});

test('has no lineup manager for a week the player was not fielded in any lineup', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager', null)
    );
});

test('excludes lineup managers from a different season', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $otherSeasonManager = SeasonManager::factory()->create();
    $otherLineup = ManagerLineup::factory()->create(['season_manager_id' => $otherSeasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $otherLineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager', null)
    );
});

test('includes ownership-affecting activity for this player, ordered chronologically', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $signing = Activity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Signing,
        'occurred_at' => now()->subDays(2),
    ]);
    $buyout = Activity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Buyout,
        'occurred_at' => now()->subDay(),
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('ownershipActivity', 2)
        ->where('ownershipActivity.0.id', $signing->id)
        ->where('ownershipActivity.1.id', $buyout->id)
    );
});

test('includes the player current team fixture for a week with no score yet', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 2,
        'team_local_id' => $player->team_id,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('teamFixtures', 1)
        ->where('teamFixtures.0.id', $fixture->id)
    );
});

test('excludes the player current team fixtures beyond the current week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $player = Player::factory()->create();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 3,
        'team_local_id' => $player->team_id,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('teamFixtures', 0));
});

test('excludes fixtures for teams other than the player current team', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $otherLocal = Team::factory()->create();
    $otherGuest = Team::factory()->create();
    $player = Player::factory()->create();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $otherLocal->id,
        'team_guest_id' => $otherGuest->id,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('teamFixtures', 0));
});

test('includes every manager join date, keyed by season manager id', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $ownerManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    ManagerPlayer::factory()->create([
        'season_manager_id' => $ownerManager->id,
        'player_id' => $player->id,
    ]);
    $ownerJoined = Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::JoinedLeague,
        'source_season_manager_id' => $ownerManager->id,
        'player_id' => null,
        'occurred_at' => now()->subDays(20),
    ]);
    $otherJoined = Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::JoinedLeague,
        'source_season_manager_id' => $otherManager->id,
        'player_id' => null,
        'occurred_at' => now()->subDays(25),
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where("teamJoinedAt.{$ownerManager->id}", $ownerJoined->occurred_at->toJSON())
        ->where("teamJoinedAt.{$otherManager->id}", $otherJoined->occurred_at->toJSON())
    );
});

test('has an empty team-joined map when no team has recorded joining the league', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('teamJoinedAt', []));
});

test('excludes non-ownership activity types like shields and weekly prizes', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    Activity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Shield,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('ownershipActivity', 0));
});

test('player ficha scores prop is built from FixtureLineup, not PlayerScore', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'team_id' => $player->team_id,
        'fantasy_points' => 9,
        'fantasy_stats' => ['marca_points' => [3, 2]],
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('scores', 1)
        ->where('scores.0.points', 9)
        ->where('scores.0.stats', ['marca_points' => [3, 2]])
    );
});

test('player ficha lineup_manager is resolved via ManagerLineupPlayer.fixture_id', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'team_id' => $player->team_id]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager.id', $seasonManager->id)
    );
});

test('excludes players with no fantasy_id from the index listing', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $linkedPlayer = Player::factory()->create(['fantasy_id' => 12345, 'status' => PlayerStatus::Ok]);
    Player::factory()->create(['fantasy_id' => null, 'status' => PlayerStatus::Ok]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $linkedPlayer->id)
    );
});

test('returns 404 for a player ficha with no fantasy_id', function (): void {
    $player = Player::factory()->create(['fantasy_id' => null]);

    $response = $this->get(route('players.show', $player));

    $response->assertNotFound();
});

test('returns 200 for a player ficha with a fantasy_id', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    $player = Player::factory()->create(['fantasy_id' => 12345]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
});
