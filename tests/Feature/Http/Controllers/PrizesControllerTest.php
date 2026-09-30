<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the prizes page with every prize and the bench miss match', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
        'current_week' => 2, 'total_weeks' => 38,
    ]);
    $fixture = Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => now()->subWeek(), 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'position' => 1]);
    $benched = Player::factory()->create();
    ManagerPlayer::factory()->create(['season_manager_id' => $manager->id, 'player_id' => $benched->id]);
    ManagerLineup::factory()->create(['season_manager_id' => $manager->id, 'week_number' => 1, 'points' => 40]);
    $miss = FixtureLineup::factory()->create(['fixture_id' => $fixture->id, 'player_id' => $benched->id, 'fantasy_points' => 12, 'fantasy_stats' => ['goals' => 1]]);

    $this->get(route('prizes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('prizes/index')
            ->where('lastFinishedWeek', 1)
            ->has('managers', 1)
            ->has('prizes', 10)
            ->where('prizes.0.key', 'noche_magica')
            ->where('prizes.3.key', 'banquillo_de_oro')
            ->where('prizes.3.rows.0.context.top_miss.fixture_lineup_id', $miss->id)
            ->where('prizes.3.rows.0.context.top_miss.fixture_id', $fixture->id)
            ->where('prizes.3.rows.0.context.top_miss.player_id', $benched->id)
            ->where('prizes.3.rows.0.context.top_miss.week_number', 1)
            ->where("players.{$benched->id}.nickname", $benched->nickname)
            ->missing('benchMisses'));
});

test('the prizes route is /premios', function (): void {
    expect(route('prizes.index', absolute: false))->toBe('/premios');
});
