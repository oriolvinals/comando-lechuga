<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
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
    $earliest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(20), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
    $latest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(10), 'team_local_id' => $player->team_id, 'state' => FixtureState::Finished]);
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
