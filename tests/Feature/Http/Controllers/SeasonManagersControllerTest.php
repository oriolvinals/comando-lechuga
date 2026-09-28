<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\FixtureLineupProbability;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the season managers index page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('season-managers.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->component('season-managers/index'));
});

test('shows the season current week and total weeks for the week selector', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 9,
        'total_weeks' => 38,
    ]);

    $response = $this->get(route('season-managers.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('filters.week', 9)
        ->where('season.current_week', 9)
        ->where('season.total_weeks', 38)
    );
});

test('clamps the requested week between 1 and the total number of weeks', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'total_weeks' => 38,
    ]);

    $tooHigh = $this->get(route('season-managers.index', ['week' => 999]));
    $tooHigh->assertInertia(fn (Assert $page): AssertableInertia => $page->where('filters.week', 38));

    $tooLow = $this->get(route('season-managers.index', ['week' => 0]));
    $tooLow->assertInertia(fn (Assert $page): AssertableInertia => $page->where('filters.week', 1));
});

test('shows the lineups for the requested week ordered by points descending', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $low = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Cruza FC']);
    $high = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Ariobretxa']);

    $lowLineup = ManagerLineup::factory()->create([
        'season_manager_id' => $low->id,
        'week_number' => 5,
        'points' => 40,
    ]);
    $highLineup = ManagerLineup::factory()->create([
        'season_manager_id' => $high->id,
        'week_number' => 5,
        'points' => 70,
    ]);
    ManagerLineup::factory()->create([
        'season_manager_id' => $high->id,
        'week_number' => 6,
        'points' => 99,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 5]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups', 2)
        ->where('lineups.0.id', $highLineup->id)
        ->where('lineups.0.season_manager.name', 'Ariobretxa')
        ->where('lineups.1.id', $lowLineup->id)
    );
});

test('shows the lineup players for each manager', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'week_number' => 1,
    ]);
    $player = Player::factory()->create(['nickname' => 'Lamine Yamal']);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'fantasy_points' => 12,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineups.0.players', 1)
        ->where('lineups.0.players.0.player.nickname', 'Lamine Yamal')
        ->where('lineups.0.players.0.points', 12)
    );
});

test('includes the current season position and market value for each index lineup player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'week_number' => 1,
    ]);
    $player = Player::factory()->create([
        'position' => PlayerPosition::Defender,
        'market_value' => 4_500_000,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.player.position', 'defender')
        ->where('lineups.0.players.0.player.market_value', 4_500_000)
    );
});

test('marks an index lineup player without points as not called up once their fixture finished', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'week_number' => 1,
    ]);

    $notCalledUpPlayer = Player::factory()->create();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $notCalledUpPlayer->team_id,
        'state' => FixtureState::Finished,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $notCalledUpPlayer->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.match_finished', true)
    );
});

test('excludes managers without a lineup for the requested week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    ManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'week_number' => 2,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('lineups', 0));
});

test('only shows lineups for the current season', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);
    $currentManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $otherSeason->id]);

    ManagerLineup::factory()->create(['season_manager_id' => $currentManager->id, 'week_number' => 1]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 1]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('lineups', 1));
});

test('renders the season manager show page', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->component('season-managers/show')
        ->where('seasonManager.id', $seasonManager->id)
    );
});

test('shows the current roster for the manager', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['nickname' => 'Pedri']);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
        'buyout_clause' => 25_000_000,
        'shielded' => true,
    ]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('roster', 1)
        ->where('roster.0.player.nickname', 'Pedri')
        ->where('roster.0.buyout_clause', 25_000_000)
        ->where('roster.0.shielded', true)
    );
});

test('shows the lineup history for the manager, most recent week first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $week1 = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    $week3 = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 3]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('lineupHistory', 2)
        ->where('lineupHistory.0.id', $week3->id)
        ->where('lineupHistory.1.id', $week1->id)
    );
});

test('includes the current season position and points for roster and lineup history players', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create([
        'position' => PlayerPosition::Striker,
        'points' => 63,
    ]);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
    ]);
    $lineup = ManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'week_number' => 1,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('roster.0.player.position', 'striker')
        ->where('roster.0.player.points', 63)
        ->where('lineupHistory.0.players.0.player.position', 'striker')
        ->where('lineupHistory.0.players.0.player.points', 63)
    );
});

test('shows the manager activity as source or target', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $asSource = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $seasonManager->id,
        'occurred_at' => now(),
    ]);
    $asTarget = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'target_season_manager_id' => $seasonManager->id,
        'type' => SeasonActivityType::Buyout,
        'occurred_at' => now()->subMinute(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('activity', 2)
        ->where('activity.0.id', $asSource->id)
        ->where('activity.1.id', $asTarget->id)
    );
});

test('includes the current season in the show payload', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 12,
        'total_weeks' => 38,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('season.current_week', 12)
        ->where('season.total_weeks', 38)
    );
});

test('attaches recent scores to each roster player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
    ]);
    $earliest = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => now()->subDays(20), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $latest = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => now()->subDays(10), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $earliest->id, 'fantasy_points' => 4]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $latest->id, 'fantasy_points' => 9]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('roster.0.player.recent_scores', [4, 9, null])
    );
});

test('marks recent scores as used only for jornadas this manager actually lined the player up', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
    ]);

    $week1 = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => now()->subDays(20), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $week2 = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => now()->subDays(10), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $week1->id, 'fantasy_points' => 4]);
    FixtureLineup::factory()->create(['player_id' => $player->id, 'fixture_id' => $week2->id, 'fantasy_points' => 9]);

    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 2]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('roster.0.player.recent_scores', [4, 9, null])
        ->where('roster.0.player.recent_scores_used', [false, true, null])
    );
});

test('only includes finished weeks where the manager topped every lineup', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 3,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1, 'points' => 70]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 1, 'points' => 40]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 2, 'points' => 20]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 2, 'points' => 50]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('wonWeeks', [1]));
});

test('marks a lineup player without points as not called up once their fixture finished', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'week_number' => 1,
    ]);

    $notCalledUpPlayer = Player::factory()->create();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $notCalledUpPlayer->team_id,
        'state' => FixtureState::Finished,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $notCalledUpPlayer->id,
    ]);

    $notYetPlayedPlayer = Player::factory()->create();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $notYetPlayedPlayer->team_id,
        'state' => FixtureState::Scheduled,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $notYetPlayedPlayer->id,
    ]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineupHistory.0.players.0.player.id', $notCalledUpPlayer->id)
        ->where('lineupHistory.0.players.0.match_finished', true)
        ->where('lineupHistory.0.players.1.player.id', $notYetPlayedPlayer->id)
        ->where('lineupHistory.0.players.1.match_finished', false)
    );
});

test('counts a tied top score as a win for both managers', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1, 'points' => 55]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 1, 'points' => 55]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('wonWeeks', [1]));
});

test('only includes finished weeks where the manager scored lowest', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 3,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1, 'points' => 20]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 1, 'points' => 50]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 2, 'points' => 70]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 2, 'points' => 40]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('lostWeeks', [1]));
});

test('counts a tied bottom score as a loss for both managers', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1, 'points' => 12]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 1, 'points' => 12]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('lostWeeks', [1]));
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
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 23]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('seasonManager.live_points', null));
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
        'state' => FixtureState::SecondHalf,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 23]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('seasonManager.live_points', 23));
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
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 23]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('seasonManager.live_points', null));
});

test('lineup player points/stats come from the linked FixtureLineup via fixture_id', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'fantasy_points' => 7,
        'fantasy_stats' => ['mins_played' => [90, 2]],
    ]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.points', 7)
        ->where('lineups.0.players.0.stats', ['mins_played' => [90, 2]])
    );
});

test('lineup player points/stats are null when fixture_id is not yet set', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'fixture_id' => null]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.points', null)
        ->where('lineups.0.players.0.stats', null)
    );
});

test('lineup player starter/subbed_out/sub_minute come from the linked FixtureLineup, null when none resolves', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'starter' => true,
        'subbed_out' => true,
        'sub_minute' => 63,
    ]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
    ]);
    // A second pick with no fixture_id — never resolves a FixtureLineup.
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'fixture_id' => null,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.starter', true)
        ->where('lineups.0.players.0.subbed_out', true)
        ->where('lineups.0.players.0.sub_minute', 63)
        ->where('lineups.0.players.1.starter', null)
        ->where('lineups.0.players.1.subbed_out', null)
        ->where('lineups.0.players.1.sub_minute', null)
    );
});

test('lineup player points fall back to the stored value when fixture_id never resolved', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'fixture_id' => null,
        'points' => 5,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.points', 5)
    );
});

test('lineup player points prefer the linked FixtureLineup over the stored fallback once fixture_id resolves', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay(), 'current_week' => 1]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'fantasy_points' => 7,
    ]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        // Stale fallback from before this player resolved a fixture_id — the
        // linked FixtureLineup's fantasy_points should win now.
        'points' => 5,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.points', 7)
    );
});

test('rates each roster player next fixture by the rival current standings position', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $ownTeam = Team::factory()->create(['main_name' => 'Own']);
    $leader = Team::factory()->create(['main_name' => 'Leader']);
    $last = Team::factory()->create(['main_name' => 'Last']);
    $season->teams()->attach([$ownTeam->id, $leader->id, $last->id]);

    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->subDays(2),
        'week_number' => 1,
        'team_local_id' => $leader->id,
        'team_guest_id' => $last->id,
        'local_score' => 2,
        'guest_score' => 0,
        'state' => FixtureState::Finished,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->addDays(2),
        'week_number' => 2,
        'team_local_id' => $ownTeam->id,
        'team_guest_id' => $leader->id,
        'state' => FixtureState::Scheduled,
    ]);
    Fixture::factory()->create([
        'season_id' => $season->id,
        'date' => now()->addDays(9),
        'week_number' => 3,
        'team_local_id' => $last->id,
        'team_guest_id' => $ownTeam->id,
        'state' => FixtureState::Scheduled,
    ]);

    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    // Out-of-league players get no next fixtures, so pin a playable status.
    $player = Player::factory()->create(['team_id' => $ownTeam->id, 'status' => PlayerStatus::Ok]);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $seasonManager->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('roster.0.player.next_fixtures.0.opponent.id', $leader->id)
        ->where('roster.0.player.next_fixtures.0.rival_position', 1)
        ->where('roster.0.player.next_fixtures.0.difficulty', fn (int|float $difficulty): bool => (float) $difficulty === -1.0)
        ->where('roster.0.player.next_fixtures.1.opponent.id', $last->id)
        ->where('roster.0.player.next_fixtures.1.rival_position', 3)
        ->where('roster.0.player.next_fixtures.1.difficulty', fn (int|float $difficulty): bool => (float) $difficulty === 1.0)
        ->where('roster.0.player.next_fixtures.2', null)
    );
});

test('ranks the manager among the season managers in every started week it has a lineup for', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 4,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id, 'total_points' => 150]);
    $secondManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $thirdManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherSeasonManager = SeasonManager::factory()->create();

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1, 'points' => 40]);
    ManagerLineup::factory()->create(['season_manager_id' => $secondManager->id, 'week_number' => 1, 'points' => 60]);
    ManagerLineup::factory()->create(['season_manager_id' => $thirdManager->id, 'week_number' => 1, 'points' => 50]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherSeasonManager->id, 'week_number' => 1, 'points' => 90]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 2, 'points' => 70]);
    ManagerLineup::factory()->create(['season_manager_id' => $secondManager->id, 'week_number' => 2, 'points' => 70]);
    ManagerLineup::factory()->create(['season_manager_id' => $thirdManager->id, 'week_number' => 2, 'points' => 10]);

    // Week 3 has no lineup for this manager; week 4 has not kicked off yet.
    ManagerLineup::factory()->create(['season_manager_id' => $secondManager->id, 'week_number' => 3, 'points' => 30]);
    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 4, 'points' => 0]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 4, 'state' => FixtureState::Scheduled]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('weekRanks', [
            1 => ['rank' => 3, 'managers' => 3, 'points' => 40, 'is_last' => true],
            2 => ['rank' => 1, 'managers' => 3, 'points' => 70, 'is_last' => false],
        ])
        ->where('weeklySummary.played_weeks', 2)
        ->where('weeklySummary.average_points', 75)
        ->where('weeklySummary.best_week', ['week_number' => 2, 'points' => 70, 'rank' => 1, 'managers' => 3])
    );
});

test('the best week keeps the earliest of two equal scores', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 3,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id, 'total_points' => 100]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1, 'points' => 50]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 1, 'points' => 80]);
    ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 2, 'points' => 50]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 2, 'points' => 20]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('weeklySummary.best_week.week_number', 1)
        ->where('weeklySummary.best_week.rank', 2)
        ->where('weeklySummary.average_points', 50)
    );
});

test('a manager with no lineups has no week ranks, average or best week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 3,
    ]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    ManagerLineup::factory()->create(['season_manager_id' => $otherManager->id, 'week_number' => 1, 'points' => 60]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('weekRanks', [])
        ->where('weeklySummary', ['played_weeks' => 0, 'average_points' => null, 'best_week' => null])
    );
});

test('shows a lineup player\'s start probability for their own upcoming fixture', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $player->team_id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'probability' => 82,
        'predicted_starter' => true,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.start.probability', 82)
        ->where('lineups.0.players.0.start.predicted_starter', true)
        ->where('lineups.0.players.0.start.confirmed_starter', null)
        ->has('lineups.0.players.0.start.fetched_at')
    );
});

test('shows the confirmed lineup instead of a probability once worldcup26 confirms it', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $player->team_id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'probability' => 60,
        'predicted_starter' => true,
    ]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $player->team_id,
        'starter' => true,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.start.confirmed_starter', true)
        ->where('lineups.0.players.0.start.probability', 60)
    );
});

test('omits a lineup player\'s start facts once their fixture has kicked off', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $player->team_id,
        'state' => FixtureState::SecondHalf,
    ]);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'probability' => 90,
        'predicted_starter' => true,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.start', null)
    );
});

test('a lineup player\'s start is null without any start data', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    $player = Player::factory()->create();
    Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $player->team_id,
        'state' => FixtureState::Scheduled,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineups.0.players.0.start', null)
    );
});

test('includes each lineup history player\'s start facts on the manager show page', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 1]);
    $player = Player::factory()->create();
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 1,
        'team_local_id' => $player->team_id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
    FixtureLineupProbability::factory()->create([
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'probability' => 45,
        'predicted_starter' => false,
    ]);
    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('lineupHistory.0.players.0.start.probability', 45)
    );
});

test('sends each roster player\'s start for his team\'s next match', function (): void {
    $season = Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addMonth()]);
    $seasonManager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create(['fantasy_id' => 15, 'short_name' => 'RMA']);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 8,
        'team_local_id' => $team->id,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);
    $listed = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    $unlisted = Player::factory()->create(['team_id' => $team->id, 'status' => PlayerStatus::Ok]);
    FixtureLineupProbability::factory()->create(['player_id' => $listed->id, 'fixture_id' => $fixture->id, 'probability' => 70, 'predicted_starter' => true]);
    ManagerPlayer::factory()->create(['season_manager_id' => $seasonManager->id, 'player_id' => $listed->id]);
    ManagerPlayer::factory()->create(['season_manager_id' => $seasonManager->id, 'player_id' => $unlisted->id]);

    $response = $this->get(route('season-managers.show', $seasonManager));

    $response->assertOk();
    $response->assertInertia(function (Assert $page) use ($listed): AssertableInertia {
        $roster = collect($page->toArray()['props']['roster'])->keyBy('player.id');

        expect($roster[$listed->id]['player']['next_start']['probability'])->toBe(70)
            ->and($roster[$listed->id]['player']['next_start']['week_number'])->toBe(8)
            ->and($roster[$listed->id]['player']['next_start']['team_short_name'])->toBe('RMA')
            ->and($roster->firstWhere('player.id', '!=', $listed->id)['player']['next_start'])->toBeNull();

        return $page;
    });
});
