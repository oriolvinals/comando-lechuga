<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamLineupPlayer;
use App\Models\SeasonTeamPlayer;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the season teams index page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('season-teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('season-teams/index'));
});

test('shows the season current week and total weeks for the week selector', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'current_week' => 9,
        'total_weeks' => 38,
    ]);

    $response = $this->get(route('season-teams.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
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

    $tooHigh = $this->get(route('season-teams.index', ['week' => 999]));
    $tooHigh->assertInertia(fn (Assert $page) => $page->where('filters.week', 38));

    $tooLow = $this->get(route('season-teams.index', ['week' => 0]));
    $tooLow->assertInertia(fn (Assert $page) => $page->where('filters.week', 1));
});

test('shows the lineups for the requested week ordered by points descending', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $low = SeasonTeam::factory()->create(['season_id' => $season->id, 'name' => 'Cruza FC']);
    $high = SeasonTeam::factory()->create(['season_id' => $season->id, 'name' => 'Ariobretxa']);

    $lowLineup = SeasonTeamLineup::factory()->create([
        'season_team_id' => $low->id,
        'week_number' => 5,
        'points' => 40,
    ]);
    $highLineup = SeasonTeamLineup::factory()->create([
        'season_team_id' => $high->id,
        'week_number' => 5,
        'points' => 70,
    ]);
    SeasonTeamLineup::factory()->create([
        'season_team_id' => $high->id,
        'week_number' => 6,
        'points' => 99,
    ]);

    $response = $this->get(route('season-teams.index', ['week' => 5]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('lineups', 2)
        ->where('lineups.0.id', $highLineup->id)
        ->where('lineups.0.season_team.name', 'Ariobretxa')
        ->where('lineups.1.id', $lowLineup->id)
    );
});

test('shows the lineup players for each team', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $lineup = SeasonTeamLineup::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'week_number' => 1,
    ]);
    $player = Player::factory()->create(['nickname' => 'Lamine Yamal']);
    SeasonTeamLineupPlayer::factory()->create([
        'season_team_lineup_id' => $lineup->id,
        'player_id' => $player->id,
        'points' => 12,
    ]);

    $response = $this->get(route('season-teams.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('lineups.0.players', 1)
        ->where('lineups.0.players.0.player.nickname', 'Lamine Yamal')
        ->where('lineups.0.players.0.points', 12)
    );
});

test('excludes teams without a lineup for the requested week', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    SeasonTeamLineup::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'week_number' => 2,
    ]);

    $response = $this->get(route('season-teams.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('lineups', 0));
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
    $currentTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $otherTeam = SeasonTeam::factory()->create(['season_id' => $otherSeason->id]);

    SeasonTeamLineup::factory()->create(['season_team_id' => $currentTeam->id, 'week_number' => 1]);
    SeasonTeamLineup::factory()->create(['season_team_id' => $otherTeam->id, 'week_number' => 1]);

    $response = $this->get(route('season-teams.index', ['week' => 1]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('lineups', 1));
});

test('renders the season team show page', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->component('season-teams/show')
        ->where('seasonTeam.id', $seasonTeam->id)
    );
});

test('shows the current roster for the team', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create(['nickname' => 'Pedri']);
    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $player->id,
        'buyout_clause' => 25_000_000,
        'shielded' => true,
    ]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('roster', 1)
        ->where('roster.0.player.nickname', 'Pedri')
        ->where('roster.0.buyout_clause', 25_000_000)
        ->where('roster.0.shielded', true)
    );
});

test('shows the lineup history for the team, most recent week first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $week1 = SeasonTeamLineup::factory()->create(['season_team_id' => $seasonTeam->id, 'week_number' => 1]);
    $week3 = SeasonTeamLineup::factory()->create(['season_team_id' => $seasonTeam->id, 'week_number' => 3]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('lineupHistory', 2)
        ->where('lineupHistory.0.id', $week3->id)
        ->where('lineupHistory.1.id', $week1->id)
    );
});

test('shows the team activity as source or target', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $otherTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $asSource = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $seasonTeam->id,
        'occurred_at' => now(),
    ]);
    $asTarget = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $otherTeam->id,
        'target_season_team_id' => $seasonTeam->id,
        'type' => SeasonActivityType::Buyout,
        'occurred_at' => now()->subMinute(),
    ]);
    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $otherTeam->id,
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
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
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('season.current_week', 12)
        ->where('season.total_weeks', 38)
    );
});

test('attaches recent scores to each roster player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();
    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $player->id,
    ]);
    $earliest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(20)]);
    $latest = Fixture::factory()->create(['season_id' => $season->id, 'date' => now()->subDays(10)]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $earliest->id, 'points' => 4]);
    PlayerScore::factory()->create(['player_id' => $player->id, 'fixture_id' => $latest->id, 'points' => 9]);

    $response = $this->get(route('season-teams.show', $seasonTeam));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('roster.0.player.recent_scores', [4, 9, null])
    );
});
