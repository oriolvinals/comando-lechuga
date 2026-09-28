<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use Illuminate\Support\Facades\DB;

/**
 * Fields a manager's pick in a given, already-finished week: creates the
 * FixtureLineup for the match (so it can be a star) and the
 * ManagerLineupPlayer that links the manager's lineup to it.
 */
function fieldPick(ManagerLineup $lineup, Player $player, Fixture $fixture, int $teamId, int $points): void
{
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $player->id,
        'team_id' => $teamId,
        'fantasy_points' => $points,
    ]);

    ManagerLineupPlayer::factory()->create([
        'manager_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'fixture_id' => $fixture->id,
        'points' => $points,
    ]);
}

test('only includes finished jornadas', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);

    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 42]);
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 2, 'points' => 0]);

    $response = $this->getJson('/api/timeline');

    $response->assertOk();
    expect($response->json('data.weeks'))->toHaveCount(1);
    $response->assertJsonPath('data.weeks.0.week_number', 1);
});

test('cumulates points and shares the better rank on a tie', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 3,
    ]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'state' => FixtureState::Scheduled]);

    $a = SeasonManager::factory()->create(['season_id' => $season->id]);
    $b = SeasonManager::factory()->create(['season_id' => $season->id]);
    $c = SeasonManager::factory()->create(['season_id' => $season->id]);

    ManagerLineup::factory()->create(['season_manager_id' => $a->id, 'week_number' => 1, 'points' => 50]);
    ManagerLineup::factory()->create(['season_manager_id' => $b->id, 'week_number' => 1, 'points' => 50]);
    ManagerLineup::factory()->create(['season_manager_id' => $c->id, 'week_number' => 1, 'points' => 30]);

    ManagerLineup::factory()->create(['season_manager_id' => $a->id, 'week_number' => 2, 'points' => 10]);
    ManagerLineup::factory()->create(['season_manager_id' => $b->id, 'week_number' => 2, 'points' => 30]);
    ManagerLineup::factory()->create(['season_manager_id' => $c->id, 'week_number' => 2, 'points' => 20]);

    $response = $this->getJson('/api/timeline');

    $response->assertOk();

    $week1 = collect($response->json('data.weeks.0.managers'))->keyBy('manager_id');
    expect($week1[$a->id]['total_points'])->toBe(50);
    expect($week1[$a->id]['rank'])->toBe(1);
    expect($week1[$b->id]['total_points'])->toBe(50);
    expect($week1[$b->id]['rank'])->toBe(1);
    expect($week1[$c->id]['total_points'])->toBe(30);
    expect($week1[$c->id]['rank'])->toBe(3);

    $week2 = collect($response->json('data.weeks.1.managers'))->keyBy('manager_id');
    expect($week2[$a->id]['total_points'])->toBe(60);
    expect($week2[$a->id]['rank'])->toBe(2);
    expect($week2[$b->id]['total_points'])->toBe(80);
    expect($week2[$b->id]['rank'])->toBe(1);
    expect($week2[$c->id]['total_points'])->toBe(50);
    expect($week2[$c->id]['rank'])->toBe(3);

    // managers are listed in rank order
    expect(collect($response->json('data.weeks.1.managers'))->pluck('manager_id')->all())
        ->toBe([$b->id, $a->id, $c->id]);
});

test('nests only the 8+ point stars a manager actually fielded, sorted by points descending', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $team = Team::factory()->create(['main_name' => 'FC Barcelona']);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);

    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 27]);

    $strongStar = Player::factory()->create(['nickname' => 'Raphinha', 'team_id' => $team->id]);
    $weakStar = Player::factory()->create(['nickname' => 'Pedri', 'team_id' => $team->id]);
    $notAStar = Player::factory()->create(['nickname' => 'Casado', 'team_id' => $team->id]);

    fieldPick($lineup, $strongStar, $fixture, $team->id, 15);
    fieldPick($lineup, $weakStar, $fixture, $team->id, 8);
    fieldPick($lineup, $notAStar, $fixture, $team->id, 7);

    $response = $this->getJson('/api/timeline');

    $response->assertOk();
    $stars = $response->json('data.weeks.0.managers.0.star_players');

    expect($stars)->toHaveCount(2);
    expect(array_column($stars, 'name'))->toBe(['Raphinha', 'Pedri']);
    expect(array_column($stars, 'points'))->toBe([15, 8]);
});

test('a star nobody fielded is absent, and a fielded star keeps the historical match team', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $currentClub = Team::factory()->create(['main_name' => 'Current Club']);
    $matchTeam = Team::factory()->create(['main_name' => 'Girona FC']);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);

    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 15]);

    // Transferred since that match: his current club differs from the team he
    // actually played for that jornada.
    $fielded = Player::factory()->create(['nickname' => 'Tsygankov', 'team_id' => $currentClub->id]);
    fieldPick($lineup, $fielded, $fixture, $matchTeam->id, 15);

    // Scored 8+ but no manager fielded him — must not appear anywhere.
    $unfielded = Player::factory()->create(['nickname' => 'Ghost', 'team_id' => $matchTeam->id]);
    FixtureLineup::factory()->create([
        'fixture_id' => $fixture->id,
        'player_id' => $unfielded->id,
        'team_id' => $matchTeam->id,
        'fantasy_points' => 12,
    ]);

    $response = $this->getJson('/api/timeline');

    $response->assertOk();
    $stars = $response->json('data.weeks.0.managers.0.star_players');

    expect($stars)->toHaveCount(1);
    expect($stars[0]['id'])->toBe($fielded->id);
    expect($stars[0]['team']['id'])->toBe($matchTeam->id);
    expect($stars[0]['team']['name'])->toBe('Girona FC');

    expect(collect($stars)->pluck('id'))->not->toContain($unfielded->id);
});

test('a manager with no qualifying stars gets an empty array', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 2,
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);

    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $lineup = ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 5]);
    $player = Player::factory()->create();
    fieldPick($lineup, $player, $fixture, $player->team_id, 5);

    $response = $this->getJson('/api/timeline');

    $response->assertOk();
    $response->assertJsonPath('data.weeks.0.managers.0.star_players', []);
});

test('shows the league name, logo and managers', function (): void {
    $season = Season::factory()->create([
        'name' => 'Liga Fantasy 2025/26',
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'total_weeks' => 38,
    ]);
    $manager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'name' => 'Comando Lechuga',
        'logo' => 'images/managers/1.png',
    ]);

    $response = $this->getJson('/api/timeline');

    $response->assertOk();
    $response->assertJsonPath('data.league.name', 'Comando Lechuga');
    $response->assertJsonPath('data.league.logo', asset('images/logo.png'));
    $response->assertJsonPath('data.league.season', 'Liga Fantasy 2025/26');
    $response->assertJsonPath('data.league.total_weeks', 38);
    $response->assertJsonPath('data.managers.0.id', $manager->id);
    $response->assertJsonPath('data.managers.0.name', 'Comando Lechuga');
    $response->assertJsonPath('data.managers.0.logo', asset('images/managers/1.png'));
});

test('carries the standard api response meta', function (): void {
    Season::factory()->create(['start_date' => now()->subDay(), 'end_date' => now()->addDay()]);

    $response = $this->getJson('/api/timeline');

    $response->assertOk();
    expect($response->json('meta'))->toHaveKeys(['generated_at', 'timezone']);
    $response->assertJsonPath('meta.timezone', 'Europe/Madrid');
});

test('runs in a constant number of queries, whatever the number of managers, weeks and stars', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 4,
    ]);
    $team = Team::factory()->create();

    $buildWeek = function (int $weekNumber, array $managers) use ($season, $team): void {
        $fixture = Fixture::factory()->create([
            'season_id' => $season->id,
            'week_number' => $weekNumber,
            'state' => FixtureState::Finished,
        ]);

        foreach ($managers as $manager) {
            $lineup = ManagerLineup::factory()->create([
                'season_manager_id' => $manager->id,
                'week_number' => $weekNumber,
                'points' => 20,
            ]);
            $player = Player::factory()->create(['team_id' => $team->id]);
            fieldPick($lineup, $player, $fixture, $team->id, 9);
        }
    };

    $countQueries = function (): int {
        // Forgets the scoped SeasonClock so each simulated request starts
        // with a cold per-jornada memo cache, exactly like a real request —
        // otherwise the second call would silently reuse the first call's
        // cached week state and undercount.
        $this->app->forgetScopedInstances();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/timeline')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $managers = [SeasonManager::factory()->create(['season_id' => $season->id])];
    $buildWeek(1, $managers);
    $withOneManagerOneWeek = $countQueries();

    $managers = array_merge($managers, [
        SeasonManager::factory()->create(['season_id' => $season->id]),
        SeasonManager::factory()->create(['season_id' => $season->id]),
    ]);
    $buildWeek(2, $managers);
    $buildWeek(3, $managers);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 4, 'state' => FixtureState::Scheduled]);
    $withThreeManagersThreeWeeks = $countQueries();

    expect($withThreeManagersThreeWeeks)->toBe($withOneManagerOneWeek);
});
