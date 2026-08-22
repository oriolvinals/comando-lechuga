<?php

declare(strict_types=1);

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamPlayer;
use App\Models\Team;
use Inertia\Testing\AssertableInertia as Assert;

test('renders the players index page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->component('players/index'));
});

test('paginates players, 30 per page', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->count(35)->create();

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.total', 35)
        ->where('players.per_page', 30)
        ->has('players.data', 30)
    );
});

test('searches players by nickname', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $match = Player::factory()->create(['nickname' => 'Lamine Yamal']);
    Player::factory()->create(['nickname' => 'Pedri']);

    $response = $this->get(route('players.index', ['search' => 'yamal']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('players.data', 1)
        ->where('players.data.0.id', $match->id)
    );
});

test('filters players by several positions at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->create(['position' => PlayerPosition::Goalkeeper]);
    Player::factory()->create(['position' => PlayerPosition::Striker]);
    Player::factory()->create(['position' => PlayerPosition::Midfield]);

    $response = $this->get(route('players.index', ['position' => 'goalkeeper,striker']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('players.data', 2));
});

test('filters players by several teams at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $first = Team::factory()->create();
    $second = Team::factory()->create();
    $third = Team::factory()->create();

    Player::factory()->create(['team_id' => $first->id]);
    Player::factory()->create(['team_id' => $second->id]);
    Player::factory()->create(['team_id' => $third->id]);

    $response = $this->get(route('players.index', ['team' => "{$first->id},{$second->id}"]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('players.data', 2));
});

test('filters players by several statuses at once', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Player::factory()->create(['status' => PlayerStatus::Injured]);
    Player::factory()->create(['status' => PlayerStatus::Suspended]);
    Player::factory()->create(['status' => PlayerStatus::Ok]);

    $response = $this->get(route('players.index', ['status' => 'injured,suspended']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->has('players.data', 2));
});

test('sorts players by points descending by default', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $low = Player::factory()->create(['points' => 10]);
    $high = Player::factory()->create(['points' => 90]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $high->id)
        ->where('players.data.1.id', $low->id)
    );
});

test('sorts players by market value when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $cheap = Player::factory()->create(['market_value' => 500_000, 'points' => 90]);
    $expensive = Player::factory()->create(['market_value' => 5_000_000, 'points' => 10]);

    $response = $this->get(route('players.index', ['sort' => 'value']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $expensive->id)
        ->where('players.data.1.id', $cheap->id)
    );
});

test('sorts players by market value difference when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $dropped = Player::factory()->create(['market_value_difference' => -50_000]);
    $risen = Player::factory()->create(['market_value_difference' => 200_000]);

    $response = $this->get(route('players.index', ['sort' => 'difference']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $risen->id)
        ->where('players.data.1.id', $dropped->id)
    );
});

test('reverses the sort direction when requested', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    $low = Player::factory()->create(['points' => 10]);
    $high = Player::factory()->create(['points' => 90]);

    $response = $this->get(route('players.index', ['direction' => 'asc']));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.id', $low->id)
        ->where('players.data.1.id', $high->id)
    );
});

test('shows the owning fantasy team when a player is owned', function (): void {
    $season = Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $seasonTeam = SeasonTeam::factory()->create(['season_id' => $season->id, 'name' => 'Ariobretxa']);
    $player = Player::factory()->create();

    SeasonTeamPlayer::factory()->create([
        'season_team_id' => $seasonTeam->id,
        'player_id' => $player->id,
    ]);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('players.data.0.owner_team.name', 'Ariobretxa')
        ->where('players.data.0.owner_team.logo', $seasonTeam->logo)
    );
});

test('shows no owner for a free player', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    Player::factory()->create();

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page->where('players.data.0.owner_team', null));
});

test('lists the real teams for the team filter', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    Team::factory()->create(['name' => 'Villarreal CF']);
    Team::factory()->create(['name' => 'Athletic Club']);

    $response = $this->get(route('players.index'));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->has('teams', 2)
        ->where('teams.0.name', 'Athletic Club')
        ->where('teams.1.name', 'Villarreal CF')
    );
});

test('echoes the current filters back', function (): void {
    Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $team = Team::factory()->create();

    $response = $this->get(route('players.index', [
        'position' => 'goalkeeper,striker',
        'team' => "{$team->id}",
        'status' => 'injured',
        'search' => 'yamal',
        'sort' => 'value',
        'direction' => 'asc',
    ]));

    $response->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('filters.position', ['goalkeeper', 'striker'])
        ->where('filters.team', [$team->id])
        ->where('filters.status', ['injured'])
        ->where('filters.search', 'yamal')
        ->where('filters.sort', 'value')
        ->where('filters.direction', 'asc')
    );
});
