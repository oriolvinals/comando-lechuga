<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;

test('paginates the current season activity, newest first', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $mostRecent = Activity::factory()->create([
        'season_id' => $season->id,
        'occurred_at' => now(),
    ]);

    for ($i = 1; $i <= 34; $i++) {
        Activity::factory()->create([
            'season_id' => $season->id,
            'occurred_at' => now()->subHours($i),
        ]);
    }

    $response = $this->getJson('/api/activity');

    $response->assertOk();
    $response->assertJsonPath('meta.total', 35);
    $response->assertJsonPath('meta.per_page', 30);
    $response->assertJsonCount(30, 'data');
    $response->assertJsonPath('data.0.id', $mostRecent->id);
});

test('returns the type and its Spanish label, source/target managers and player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $source = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Comando Lechuga']);
    $target = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Ariobretxa']);
    $player = Player::factory()->create(['nickname' => 'Pedri']);

    $activity = Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Buyout,
        'source_season_manager_id' => $source->id,
        'target_season_manager_id' => $target->id,
        'player_id' => $player->id,
        'amount' => 500_000,
    ]);

    $response = $this->getJson('/api/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $activity->id);
    $response->assertJsonPath('data.0.type', 'buyout');
    $response->assertJsonPath('data.0.type_label', 'Cláusula');
    $response->assertJsonPath('data.0.source_season_manager', ['id' => $source->id, 'name' => 'Comando Lechuga']);
    $response->assertJsonPath('data.0.target_season_manager', ['id' => $target->id, 'name' => 'Ariobretxa']);
    $response->assertJsonPath('data.0.player', ['id' => $player->id, 'nickname' => 'Pedri']);
    $response->assertJsonPath('data.0.amount', 500_000);
});

test('has a null target_season_manager and player when the activity has none', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::JoinedLeague,
        'target_season_manager_id' => null,
        'player_id' => null,
        'amount' => null,
    ]);

    $response = $this->getJson('/api/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.target_season_manager', null);
    $response->assertJsonPath('data.0.player', null);
    $response->assertJsonPath('data.0.amount', null);
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

    Activity::factory()->create([
        'season_id' => $season->id,
        'player_id' => $player->id,
        'amount' => 500_000,
        'occurred_at' => $activityDate,
    ]);

    $response = $this->getJson('/api/activity');

    $response->assertOk();
    $response->assertJsonPath('data.0.value_difference', 50_000);
});

test('filters activity by manager, matching either source or target', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $otherManager = SeasonManager::factory()->create(['season_id' => $season->id]);

    $asSource = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'occurred_at' => now()->subMinute(),
    ]);
    $asTarget = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'target_season_manager_id' => $manager->id,
        'occurred_at' => now(),
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $otherManager->id,
        'occurred_at' => now(),
    ]);

    $response = $this->getJson("/api/activity?manager={$manager->id}");

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('data.0.id', $asTarget->id);
    $response->assertJsonPath('data.1.id', $asSource->id);
});

test('filters activity by several managers at once', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $first = SeasonManager::factory()->create(['season_id' => $season->id]);
    $second = SeasonManager::factory()->create(['season_id' => $season->id]);
    $third = SeasonManager::factory()->create(['season_id' => $season->id]);

    Activity::factory()->create(['season_id' => $season->id, 'source_season_manager_id' => $first->id]);
    Activity::factory()->create(['season_id' => $season->id, 'source_season_manager_id' => $second->id]);
    Activity::factory()->create(['season_id' => $season->id, 'source_season_manager_id' => $third->id]);

    $response = $this->getJson("/api/activity?manager={$first->id},{$second->id}");

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

test('filters activity by type', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $signing = Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Signing]);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Sale]);

    $response = $this->getJson('/api/activity?type=signing');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $signing->id);
});

test('filters activity by several types at once', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Signing]);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Sale]);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Shield]);

    $response = $this->getJson('/api/activity?type=signing,sale');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

test('filters activity by player', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $player = Player::factory()->create();
    $otherPlayer = Player::factory()->create();

    $match = Activity::factory()->create(['season_id' => $season->id, 'player_id' => $player->id]);
    Activity::factory()->create(['season_id' => $season->id, 'player_id' => $otherPlayer->id]);

    $response = $this->getJson("/api/activity?player={$player->id}");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $match->id);
});

test('filters activity by several players at once', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $first = Player::factory()->create();
    $second = Player::factory()->create();
    $third = Player::factory()->create();

    Activity::factory()->create(['season_id' => $season->id, 'player_id' => $first->id]);
    Activity::factory()->create(['season_id' => $season->id, 'player_id' => $second->id]);
    Activity::factory()->create(['season_id' => $season->id, 'player_id' => $third->id]);

    $response = $this->getJson("/api/activity?player={$first->id},{$second->id}");

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
});

test('combines manager, player and type filters', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();

    $match = Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Signing,
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'source_season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'type' => SeasonActivityType::Sale,
    ]);

    $response = $this->getJson("/api/activity?manager={$manager->id}&player={$player->id}&type=signing");

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $match->id);
});
