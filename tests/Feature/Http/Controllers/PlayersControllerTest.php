<?php

declare(strict_types=1);

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamLineupPlayer;
use App\Models\SeasonTeamPlayer;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the players index page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('players/index'));
});

test('paginates players, 30 per page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->count(35)->create();

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.total', 35)
        ->where('players.per_page', 30)
        ->has('players.data', 30)
    );
});

test('searches players by nickname', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $match = Player::factory()->create(['nickname' => 'Lamine Yamal']);
    Player::factory()->create(['nickname' => 'Pedri']);

    $response = $this->get(route('players.index', ['search' => 'yamal']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $match->id)
    );
});

test('searches players by nickname ignoring accents', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $match = Player::factory()->create(['nickname' => 'Óscar Valentín']);
    Player::factory()->create(['nickname' => 'Pedri']);

    $response = $this->get(route('players.index', ['search' => 'valentin']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $match->id)
    );
});

test('filters players by several positions at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->create(['position' => PlayerPosition::Goalkeeper]);
    Player::factory()->create(['position' => PlayerPosition::Striker]);
    Player::factory()->create(['position' => PlayerPosition::Midfield]);

    $response = $this->get(route('players.index', ['position' => 'goalkeeper,striker']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('players.data', 2));
});

test('filters players by several teams at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $third = Team::factory()->create();

    Player::factory()->create(['team_id' => $first->id]);
    Player::factory()->create(['team_id' => $second->id]);
    Player::factory()->create(['team_id' => $third->id]);

    $response = $this->get(route('players.index', ['team' => "{$first->id},{$second->id}"]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('players.data', 2));
});

test('filters players by fantasy season team', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $ownerTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $otherTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $owned = Player::factory()->create();
    SeasonTeamPlayer::factory()->create(['season_team_id' => $ownerTeam->id, 'player_id' => $owned->id]);

    Player::factory()->create();

    $response = $this->get(route('players.index', ['season_team' => (string) $ownerTeam->id]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $owned->id)
    );
});

test('lists fantasy season teams for the current season only, for the team filter', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);

    $currentTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    SeasonTeam::factory()->create(['season_id' => $otherSeason->id]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('seasonTeams', 1)
        ->where('seasonTeams.0.id', $currentTeam->id)
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
    $response->assertInertia(fn (Assert $page) => $page->has('players.data', 2));
});

test('sorts players by points descending by default', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $low = Player::factory()->create(['points' => 10]);
    $high = Player::factory()->create(['points' => 90]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $high->id)
        ->where('players.data.1.id', $low->id)
    );
});

test('sorts players by market value when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $cheap = Player::factory()->create(['market_value' => 500_000, 'points' => 90]);
    $expensive = Player::factory()->create(['market_value' => 5_000_000, 'points' => 10]);

    $response = $this->get(route('players.index', ['sort' => 'value']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $expensive->id)
        ->where('players.data.1.id', $cheap->id)
    );
});

test('sorts players by market value difference when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $dropped = Player::factory()->create(['market_value_difference' => -50_000]);
    $risen = Player::factory()->create(['market_value_difference' => 200_000]);

    $response = $this->get(route('players.index', ['sort' => 'difference']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $risen->id)
        ->where('players.data.1.id', $dropped->id)
    );
});

test('reverses the sort direction when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $low = Player::factory()->create(['points' => 10]);
    $high = Player::factory()->create(['points' => 90]);

    $response = $this->get(route('players.index', ['direction' => 'asc']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $low->id)
        ->where('players.data.1.id', $high->id)
    );
});

test('shows the owning fantasy team when a player is owned', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create([
        'season_id' => $season->id,
        'name' => 'Ariobretxa',
        'primary_color' => '#c4ff3d',
    ]);
    $player = Player::factory()->create();

    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.owner_team.id', $seasonTeam->id)
        ->where('players.data.0.owner_team.name', 'Ariobretxa')
        ->where('players.data.0.owner_team.logo', $seasonTeam->logo)
        ->where('players.data.0.owner_team.primary_color', '#c4ff3d')
    );
});

test('shows no owner for a free player', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    Player::factory()->create();

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('players.data.0.owner_team', null));
});

test('includes points for the last 3 played matches, ordered by fixture date oldest first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    // Week numbers deliberately out of date order, so the test actually exercises
    // sorting by fixture date rather than by week_number or insertion order.
    $earliest = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 4, 'date' => now()->subDays(30)]);
    $middle = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => now()->subDays(20)]);
    $latest = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 5, 'date' => now()->subDays(10)]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $earliest->id, 'points' => 7]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $middle->id, 'points' => 2]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $latest->id, 'points' => 11]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.recent_scores', [7, 2, 11])
    );
});

test('only takes the 3 most recent matches, dropping older ones', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $oldest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(40)]);
    $second = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(30)]);
    $third = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(20)]);
    $fourth = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(10)]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $oldest->id, 'points' => 99]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $second->id, 'points' => 3]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $third->id, 'points' => 5]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $fourth->id, 'points' => 8]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.recent_scores', [3, 5, 8])
    );
});

test('pads with null at the end when a player has fewer than 3 matches of history', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDay()]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id, 'points' => 9]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.recent_scores', [9, null, null])
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
    $player = Player::factory()->create();
    $otherSeasonFixture = Fixture::factory()->create(['season_id' => $otherSeason->id]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $otherSeasonFixture->id, 'points' => 9]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.recent_scores', [null, null, null])
    );
});

test('lists the real teams for the team filter', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Team::factory()->create(['name' => 'Villarreal CF']);
    Team::factory()->create(['name' => 'Athletic Club']);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('teams', 2)
        ->where('teams.0.name', 'Athletic Club')
        ->where('teams.1.name', 'Villarreal CF')
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
    $response->assertInertia(fn (Assert $page) => $page
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
    $response->assertInertia(fn (Assert $page) => $page
        ->component('players/show')
        ->where('player.id', $player->id)
    );
});

test('shows the owning team and clause details when the player is owned', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id, 'name' => 'Gauchitos F.C']);
    $player = Player::factory()->create();
    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $player->id,
        'buyout_clause' => 4_272_558,
        'shielded' => false,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('owner.season_team.name', 'Gauchitos F.C')
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
    $response->assertInertia(fn (Assert $page) => $page->where('owner', null));
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
    $response->assertInertia(fn (Assert $page) => $page
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
    $response->assertInertia(fn (Assert $page) => $page->where('marketListing', null));
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
    $response->assertInertia(fn (Assert $page) => $page
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
    $response->assertInertia(fn (Assert $page) => $page->has('marketHistory', 0));
});

test('orders the player scores by week number ascending', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $weekTwoFixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2]);
    $weekOneFixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    $weekTwoScore = PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $weekTwoFixture->id]);
    $weekOneScore = PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $weekOneFixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
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
    PlayerScore::factory()->create(['player_id' => $otherPlayer->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('scores', 0));
});

test('includes the fantasy team that fielded the player in their lineup that week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $lineup = SeasonTeamLineup::factory()->create(['season_team_id' => $seasonTeam->id, 'week_number' => 1]);
    SeasonTeamLineupPlayer::factory()->create(['season_team_lineup_id' => $lineup->id, 'player_id' => $player->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('scores.0.lineup_team.id', $seasonTeam->id)
    );
});

test('has no lineup team for a week the player was not fielded in any lineup', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('scores.0.lineup_team', null)
    );
});

test('excludes lineup teams from a different season', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $fixture->id]);

    $otherSeasonTeam = SeasonTeam::factory()->create();
    $otherLineup = SeasonTeamLineup::factory()->create(['season_team_id' => $otherSeasonTeam->id, 'week_number' => 1]);
    SeasonTeamLineupPlayer::factory()->create(['season_team_lineup_id' => $otherLineup->id, 'player_id' => $player->id]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('scores.0.lineup_team', null)
    );
});

test('includes ownership-affecting activity for this player, ordered chronologically', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $signing = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Signing,
        'occurred_at' => now()->subDays(2),
    ]);
    $buyout = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Buyout,
        'occurred_at' => now()->subDay(),
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
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
    $response->assertInertia(fn (Assert $page) => $page
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
    $response->assertInertia(fn (Assert $page) => $page->has('teamFixtures', 0));
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
    $response->assertInertia(fn (Assert $page) => $page->has('teamFixtures', 0));
});

test('includes every team join date, keyed by season team id', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $ownerTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $otherTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $ownerTeam->id,
        'player_id' => $player->id,
    ]);
    $ownerJoined = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::JoinedLeague,
        'source_season_team_id' => $ownerTeam->id,
        'player_id' => null,
        'occurred_at' => now()->subDays(20),
    ]);
    $otherJoined = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::JoinedLeague,
        'source_season_team_id' => $otherTeam->id,
        'player_id' => null,
        'occurred_at' => now()->subDays(25),
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where("teamJoinedAt.{$ownerTeam->id}", $ownerJoined->occurred_at->toJSON())
        ->where("teamJoinedAt.{$otherTeam->id}", $otherJoined->occurred_at->toJSON())
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
    $response->assertInertia(fn (Assert $page) => $page->where('teamJoinedAt', []));
});

test('excludes non-ownership activity types like shields and weekly prizes', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Shield,
    ]);

    $response = $this->get(route('players.show', $player));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('ownershipActivity', 0));
});
