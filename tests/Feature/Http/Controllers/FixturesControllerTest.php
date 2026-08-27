<?php

declare(strict_types=1);

use App\Enums\PlayerPosition;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\SeasonManagerLineup;
use App\Models\SeasonManagerLineupPlayer;
use App\Models\Team;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

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

test('excludes coaches from the scores', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $fixture = Fixture::factory()->create();
    $coach = Player::factory()->create(['position' => PlayerPosition::Coach]);
    PlayerScore::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $coach->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('scores', 0));
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
    $lineup = SeasonManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 3]);
    SeasonManagerLineupPlayer::factory()->create(['season_manager_lineup_id' => $lineup->id, 'player_id' => $player->id]);

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
    $otherWeekLineup = SeasonManagerLineup::factory()->create(['season_manager_id' => $seasonManager->id, 'week_number' => 4]);
    SeasonManagerLineupPlayer::factory()->create(['season_manager_lineup_id' => $otherWeekLineup->id, 'player_id' => $player->id]);

    $response = $this->get(route('fixtures.show', $fixture));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('scores.0.lineup_manager', null)
    );
});
