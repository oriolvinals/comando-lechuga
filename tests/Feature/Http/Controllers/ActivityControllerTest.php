<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonManager;
use Inertia\Testing\AssertableInertia;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the activity index page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->component('activity/index'));
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
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('activities.total', 35)
        ->where('activities.per_page', 30)
        ->has('activities.data', 30)
        ->where('activities.data.0.id', $mostRecent->id)
    );
});

test('filters activity by manager, matching either source or target', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $asSource = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'occurred_at' => now()->subMinute(),
    ]);
    $asTarget = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'target_season_manager_id' => $manager->id,
        'occurred_at' => now(),
    ]);
    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'occurred_at' => now(),
    ]);

    $response = $this->get(route('activity.index', ['manager' => (string) $manager->id]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('activities.data', 2)
        ->where('activities.data.0.id', $asTarget->id)
        ->where('activities.data.1.id', $asSource->id)
    );
});

test('filters activity by several managers at once', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $first = SeasonManager::factory()->create(['season_id' => $season->id]);
    $second = SeasonManager::factory()->create(['season_id' => $season->id]);
    $third = SeasonManager::factory()->create(['season_id' => $season->id]);

    $fromFirst = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $first->id,
        'occurred_at' => now(),
    ]);
    $fromSecond = SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $second->id,
        'occurred_at' => now()->subMinute(),
    ]);
    SeasonActivity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $third->id,
        'occurred_at' => now()->subMinutes(2),
    ]);

    $response = $this->get(route('activity.index', ['manager' => "{$first->id},{$second->id}"]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
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
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
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
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->has('activities.data', 2));
});

test('lists the current season managers for the manager filter', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $otherSeason = Season::factory()->create([
        'start_date' => now()->subYears(2),
        'end_date' => now()->subYear(),
    ]);

    SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Cruza FC']);
    SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Ariobretxa']);
    SeasonManager::factory()->create(['season_id' => $otherSeason->id, 'name' => 'Old manager']);

    $response = $this->get(route('activity.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->has('managers', 2)
        ->where('managers.0.name', 'Ariobretxa')
        ->where('managers.1.name', 'Cruza FC')
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
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
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
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('activities.data.0.value_difference', null));
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
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page->where('activities.data.0.value_difference', null));
});

test('echoes the current manager and type filters back as lists', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $response = $this->get(route('activity.index', ['manager' => "{$manager->id}", 'type' => 'signing,sale']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page): AssertableInertia => $page
        ->where('filters.manager', [$manager->id])
        ->where('filters.type', ['signing', 'sale'])
    );
});
