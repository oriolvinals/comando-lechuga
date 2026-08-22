<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the activity index page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('activity/index'));
});

test('paginates the current season activity, newest first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $mostRecent = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'occurred_at' => now(),
    ]);

    for ($i = 1; $i <= 34; $i++) {
        SeasonActivity::factory()->create([
            'season_id' => $season->id,
            'occurred_at' => now()->subHours($i),
        ]);
    }

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('activities.total', 35)
        ->where('activities.per_page', 30)
        ->has('activities.data', 30)
        ->where('activities.data.0.id', $mostRecent->id)
    );
});

test('filters activity by team, matching either source or target', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $team = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $otherTeam = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $asSource = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $team->id,
        'occurred_at' => now()->subMinute(),
    ]);
    $asTarget = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $otherTeam->id,
        'target_season_team_id' => $team->id,
        'occurred_at' => now(),
    ]);
    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $otherTeam->id,
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('activity.index', ['team' => (string) $team->id]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('activities.data', 2)
        ->where('activities.data.0.id', $asTarget->id)
        ->where('activities.data.1.id', $asSource->id)
    );
});

test('filters activity by several teams at once', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $first = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $second = SeasonTeam::factory()->create(['season_id' => $season->id]);
    $third = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $fromFirst = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $first->id,
        'occurred_at' => now(),
    ]);
    $fromSecond = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $second->id,
        'occurred_at' => now()->subMinute(),
    ]);
    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_team_id' => $third->id,
        'occurred_at' => now()->subMinutes(2),
    ]);

    $response = $this->get(route('activity.index', ['team' => "{$first->id},{$second->id}"]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('activities.data', 2)
        ->where('activities.data.0.id', $fromFirst->id)
        ->where('activities.data.1.id', $fromSecond->id)
    );
});

test('filters activity by type', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $signing = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
    ]);
    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Sale,
    ]);

    $response = $this->get(route('activity.index', ['type' => SeasonActivityType::Signing->value]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('activities.data', 1)
        ->where('activities.data.0.id', $signing->id)
    );
});

test('filters activity by several types at once', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    SeasonActivity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Signing]);
    SeasonActivity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Sale]);
    SeasonActivity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Shield]);

    $response = $this->get(route('activity.index', ['type' => 'signing,sale']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('activities.data', 2));
});

test('lists the current season teams for the team filter', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);

    SeasonTeam::factory()->create(['season_id' => $season->id, 'name' => 'Cruza FC']);
    SeasonTeam::factory()->create(['season_id' => $season->id, 'name' => 'Ariobretxa']);
    SeasonTeam::factory()->create(['season_id' => $otherSeason->id, 'name' => 'Old team']);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('teams', 2)
        ->where('teams.0.name', 'Ariobretxa')
        ->where('teams.1.name', 'Cruza FC')
    );
});

test('shows the difference between the amount paid and the market value on that date', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $activityDate = now()->subDays(3);

    PlayerMarket::factory()->create([
        'player_id' => $player->id,
        'date' => $activityDate->toDateString(),
        'value' => 450_000,
    ]);

    $activity = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'amount' => 500_000,
        'occurred_at' => $activityDate,
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('activities.data.0.id', $activity->id)
        ->where('activities.data.0.value_difference', 50_000)
    );
});

test('has no value difference when there is no market snapshot for that date', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();

    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'amount' => 500_000,
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('activities.data.0.value_difference', null));
});

test('has no value difference when the activity has no player or no amount', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::JoinedLeague,
        'player_id' => null,
        'amount' => null,
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('activities.data.0.value_difference', null));
});

test('echoes the current team and type filters back as lists', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = SeasonTeam::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('activity.index', ['team' => "{$team->id}", 'type' => 'signing,sale']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.team', [$team->id])
        ->where('filters.type', ['signing', 'sale'])
    );
});
