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

function managerApiSeason(int $currentWeek = 1): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDays(30),
        'end_date' => now()->addDays(200),
        'current_week' => $currentWeek,
    ]);
}

function managerApiFixture(Season $season, int $weekNumber, FixtureState $state, Team $local, mixed $date): Fixture
{
    return Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => $weekNumber,
        'state' => $state,
        'team_local_id' => $local->id,
        'date' => $date,
    ]);
}

test('returns the manager info fields', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'name' => 'Comando Lechuga',
        'position' => 1,
        'last_position' => 2,
        'total_points' => 812,
        'value' => 123_456_789,
        'live_points' => 40,
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.id', $manager->id);
    $response->assertJsonPath('data.url', route('api.managers.show', $manager->id));
    $response->assertJsonPath('data.name', 'Comando Lechuga');
    $response->assertJsonPath('data.rank', 1);
    $response->assertJsonPath('data.last_rank', 2);
    $response->assertJsonPath('data.total_points', 812);
    $response->assertJsonPath('data.squad_value', 123_456_789);
    $response->assertJsonPath('data.live_points', null);
});

test('returns 404 for a manager that does not exist', function (): void {
    $this->getJson('/api/managers/999999')->assertNotFound();
});

test('returns each roster player in the full player shape with his purchase and clause', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $player = Player::factory()->create([
        'nickname' => 'Pedri',
        'status' => PlayerStatus::Ok,
        'team_id' => $team->id,
        'average_points' => 6.5,
    ]);
    $lockedUntil = now()->addDays(3);
    ManagerPlayer::factory()->create([
        'season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'buyout_clause' => 90_000_000,
        'buyout_clause_locked_until' => $lockedUntil,
        'shielded' => true,
        'shielded_until' => now()->addDay(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'amount' => 70_000_000,
        'occurred_at' => now()->subDays(5),
    ]);
    $next = managerApiFixture($season, 2, FixtureState::Scheduled, $team, now()->addDays(2));
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $player->id, 'probability' => 80]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.roster');
    $response->assertJsonPath('data.roster.0.player.id', $player->id);
    $response->assertJsonPath('data.roster.0.player.team.name', 'FC Barcelona');
    $response->assertJsonPath('data.roster.0.player.status', 'ok');
    $response->assertJsonPath('data.roster.0.player.average_points', 6.5);
    $response->assertJsonPath('data.roster.0.player.next_start.probability', 80);
    $response->assertJsonPath('data.roster.0.player.owner_manager.id', $manager->id);
    $response->assertJsonPath('data.roster.0.purchase.amount', 70_000_000);
    $response->assertJsonPath('data.roster.0.purchase.type', 'signing');
    $response->assertJsonPath('data.roster.0.buyout_clause.amount', 90_000_000);
    $response->assertJsonPath('data.roster.0.buyout_clause.locked_until', $lockedUntil->toIso8601String());
    $response->assertJsonPath('data.roster.0.buyout_clause.is_locked', true);
    $response->assertJsonPath('data.roster.0.buyout_clause.shielded', true);
});

test('splits the current jornada\'s lineup from the finished lineup history', function (): void {
    $season = managerApiSeason(2);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id, 'position' => PlayerPosition::Midfield]);
    managerApiFixture($season, 1, FixtureState::Finished, $team, now()->subDays(6));
    managerApiFixture($season, 2, FixtureState::Postponed, Team::factory()->create(), now()->addDay());
    $lock = now()->addDays(2)->setTime(18, 30);
    $next = managerApiFixture($season, 2, FixtureState::Scheduled, $team, $lock);
    FixtureLineupProbability::factory()->create(['fixture_id' => $next->id, 'player_id' => $player->id, 'probability' => 75]);

    $week1 = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 45, 'tactical_formation' => [4, 4, 2]]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $week1->id, 'player_id' => $player->id, 'position' => PlayerPosition::Midfield, 'points' => 9]);
    $week2 = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2, 'points' => 0, 'tactical_formation' => [4, 3, 3]]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $week2->id, 'player_id' => $player->id, 'position' => PlayerPosition::Midfield, 'points' => null]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data.lineup_history');
    $response->assertJsonPath('data.lineup_history.0.week_number', 1);
    $response->assertJsonPath('data.lineup_history.0.formation', '4-4-2');
    $response->assertJsonPath('data.lineup_history.0.tactical_formation', [4, 4, 2]);
    $response->assertJsonPath('data.lineup_history.0.players.0.points', 9);
    $response->assertJsonPath('data.lineup_history.0.players.0.match_finished', true);
    $response->assertJsonPath('data.current_lineup.week_number', 2);
    $response->assertJsonPath('data.current_lineup.week_state', 'not_started');
    $response->assertJsonPath('data.current_lineup.formation', '4-3-3');
    $response->assertJsonPath('data.current_lineup.lineup_locks_at', $lock->toIso8601String());
    $response->assertJsonPath('data.current_lineup.players.0.player.id', $player->id);
    $response->assertJsonPath('data.current_lineup.players.0.position', 'midfield');
    $response->assertJsonPath('data.current_lineup.players.0.match.fixture_id', $next->id);
    $response->assertJsonPath('data.current_lineup.players.0.match.state', 'scheduled');
    $response->assertJsonPath('data.current_lineup.players.0.points', null);
    $response->assertJsonPath('data.current_lineup.players.0.next_start.probability', 75);
});

test('shows live points and no next start for a lineup player whose match is live', function (): void {
    $season = managerApiSeason(2);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'live_points' => 33]);
    $liveTeam = Team::factory()->create();
    $laterTeam = Team::factory()->create();
    $playing = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $liveTeam->id]);
    $waiting = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $laterTeam->id]);
    $live = managerApiFixture($season, 2, FixtureState::FirstHalf, $liveTeam, now()->subMinutes(30));
    $later = managerApiFixture($season, 2, FixtureState::Scheduled, $laterTeam, now()->addDay());
    FixtureLineup::factory()->create(['fixture_id' => $live->id, 'player_id' => $playing->id, 'team_id' => $liveTeam->id, 'fantasy_points' => 7]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $live->id, 'player_id' => $playing->id, 'probability' => 90]);
    FixtureLineupProbability::factory()->create(['fixture_id' => $later->id, 'player_id' => $waiting->id, 'probability' => 65]);

    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $playing->id, 'fixture_id' => $live->id, 'position' => PlayerPosition::Striker]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $waiting->id, 'fixture_id' => null, 'position' => PlayerPosition::Defender]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.live_points', 33);
    $response->assertJsonPath('data.current_lineup.week_state', 'live');
    $response->assertJsonPath('data.current_lineup.players.0.match.state', 'first_half');
    $response->assertJsonPath('data.current_lineup.players.0.points', 7);
    $response->assertJsonPath('data.current_lineup.players.0.next_start', null);
    $response->assertJsonPath('data.current_lineup.players.1.points', null);
    $response->assertJsonPath('data.current_lineup.players.1.next_start.probability', 65);
});

test('uses the rescheduled fixture, not the postponed one, for a lineup player of the same team and jornada', function (): void {
    $season = managerApiSeason(2);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $team = Team::factory()->create();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok, 'team_id' => $team->id]);
    $rescheduled = managerApiFixture($season, 2, FixtureState::Scheduled, $team, now()->addDays(2));
    managerApiFixture($season, 2, FixtureState::Postponed, $team, now()->addDays(5));
    FixtureLineupProbability::factory()->create(['fixture_id' => $rescheduled->id, 'player_id' => $player->id, 'probability' => 70]);

    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2]);
    ManagerLineupPlayer::factory()->create(['manager_lineup_id' => $lineup->id, 'player_id' => $player->id, 'fixture_id' => null]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.current_lineup.players.0.match.fixture_id', $rescheduled->id);
    $response->assertJsonPath('data.current_lineup.players.0.match.state', 'scheduled');
    $response->assertJsonPath('data.current_lineup.players.0.next_start.fixture_id', $rescheduled->id);
    $response->assertJsonPath('data.current_lineup.players.0.next_start.probability', 70);
});

test('has a null current lineup when the manager has none for the jornada', function (): void {
    $season = managerApiSeason(2);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    managerApiFixture($season, 2, FixtureState::Scheduled, Team::factory()->create(), now()->addDay());

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.current_lineup', null);
    $response->assertJsonPath('data.lineup_history', []);
});

test('ranks the manager in each finished jornada and averages only jornadas with a lineup', function (): void {
    $season = managerApiSeason(3);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $rival = SeasonManager::factory()->create(['season_id' => $season->id]);
    managerApiFixture($season, 3, FixtureState::Scheduled, Team::factory()->create(), now()->addDay());
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 50]);
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2, 'points' => 70]);
    ManagerLineup::factory()->create(['season_manager_id' => $rival->id, 'week_number' => 1, 'points' => 60]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonPath('data.played_weeks', 2);
    expect($response->json('data.average_points'))->toEqual(60.0);
    $response->assertJsonPath('data.week_ranks', [
        ['week_number' => 1, 'rank' => 2, 'managers' => 2, 'points' => 50, 'is_last' => true],
        ['week_number' => 2, 'rank' => 1, 'managers' => 1, 'points' => 70, 'is_last' => false],
    ]);
});

test('sums the squad\'s daily value difference', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    foreach ([300_000, -100_000] as $difference) {
        ManagerPlayer::factory()->create([
            'season_manager_id' => $manager->id,
            'player_id' => Player::factory()->create(['status' => PlayerStatus::Ok, 'market_value_difference' => $difference])->id,
        ]);
    }

    $this->getJson("/api/managers/{$manager->id}")->assertJsonPath('data.daily_value_difference', 200_000);
});

test('returns the manager\'s last 10 activities as source or target, newest first', function (): void {
    $season = managerApiSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $asTarget = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'target_season_manager_id' => $manager->id,
        'occurred_at' => now(),
    ]);
    $asSource = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'occurred_at' => now()->subMinute(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'occurred_at' => now(),
    ]);

    $response = $this->getJson("/api/managers/{$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(2, 'data.recent_activity');
    $response->assertJsonPath('data.recent_activity.0.id', $asTarget->id);
    $response->assertJsonPath('data.recent_activity.1.id', $asSource->id);
});
