<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\PlayerStatus;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use Inertia\Testing\AssertableInertia as Assert;

function comparisonSeason(array $attributes = []): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        ...$attributes,
    ]);
}

test('the comparator route does not collide with the player ficha route', function (): void {
    comparisonSeason();

    expect(route('players.compare', absolute: false))->toBe('/jugadores/comparar');

    $this->get('/jugadores/comparar')
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('players/compare'));
});

test('without ids it renders the empty comparator', function (): void {
    comparisonSeason();

    $this->get(route('players.compare'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('players/compare')
            ->where('ids', [])
            ->where('players', []));
});

test('keeps valid ids in the requested order and silently drops repeated, unknown and malformed ones', function (): void {
    comparisonSeason();
    $first = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $second = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $withoutFantasyId = Player::factory()->create(['fantasy_id' => null, 'status' => PlayerStatus::Ok]);
    $withoutSeason = Player::factory()->create(['status' => PlayerStatus::Ok]);
    PlayerSeason::query()->where('player_id', $withoutSeason->id)->delete();

    $ids = implode(',', [$second->id, 'abc', $first->id, $second->id, 999_999, $withoutFantasyId->id, $withoutSeason->id, '-3', '']);

    $this->get(route('players.compare', ['ids' => $ids]))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('ids', [$second->id, $first->id]));
});

test('an ids array instead of a string is ignored', function (): void {
    comparisonSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);

    $this->get('/jugadores/comparar?ids[]='.$player->id)
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('ids', []));
});

test('takes at most three players, the first three valid ones', function (): void {
    comparisonSeason();
    $players = Player::factory()->count(4)->create(['status' => PlayerStatus::Ok]);

    $this->get(route('players.compare', ['ids' => $players->pluck('id')->implode(',')]))
        ->assertInertia(fn (Assert $page): Assert => $page->where('ids', $players->take(3)->pluck('id')->all()));
});

test('accepts an old link with vista and ignores it', function (): void {
    comparisonSeason();
    $player = Player::factory()->create(['status' => PlayerStatus::Ok]);

    $this->get(route('players.compare', ['ids' => (string) $player->id, 'vista' => 'b']))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('ids', [$player->id])
            ->missing('view'));
});

test('lists the active season managers with their colour', function (): void {
    $season = comparisonSeason();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id, 'name' => 'Gauchitos F.C', 'primary_color' => '#ff0000']);
    SeasonManager::factory()->create(['season_id' => Season::factory()->create()->id]);

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('managers', 1)
            ->where('managers.0.id', $manager->id)
            ->where('managers.0.name', 'Gauchitos F.C')
            ->where('managers.0.color', '#ff0000'));
});

test('currentWeek is the current jornada before it kicks off and the next one once it has', function (): void {
    $season = comparisonSeason(['current_week' => 5]);
    $fixture = Fixture::factory()->create([
        'season_id' => $season->id,
        'week_number' => 5,
        'date' => now()->addDay(),
        'state' => FixtureState::Scheduled,
    ]);

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('currentWeek', 5));

    $fixture->update(['date' => now()->subHour(), 'state' => FixtureState::FirstHalf]);
    app()->forgetScopedInstances();

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('currentWeek', 6));
});

test('currentWeek never goes past the jornada after the last one, and totalWeeks is sent', function (): void {
    comparisonSeason(['current_week' => 40, 'total_weeks' => 38]);

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->where('currentWeek', 39)
            ->where('totalWeeks', 38));
});

test('sends the compared players in the order of ids', function (): void {
    comparisonSeason();
    $first = Player::factory()->create(['status' => PlayerStatus::Ok]);
    $second = Player::factory()->create(['status' => PlayerStatus::Ok]);

    $this->get(route('players.compare', ['ids' => "{$second->id},{$first->id}"]))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('players', 2)
            ->where('players.0.id', $second->id)
            ->where('players.1.id', $first->id)
            ->has('players.0.scores')
            ->has('players.0.next_fixtures', 3));
});

test('godMode is false on the comparator without the key or with a wrong one', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    comparisonSeason();

    $this->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('godMode', false));

    $this->get(route('players.compare').'?god_mode=wrong-key')
        ->assertRedirect(route('players.compare'))
        ->assertCookieMissing('god_mode');
});

test('the configured key turns godMode on for the comparator through the remembered cookie', function (): void {
    config(['services.god_mode.key' => 'super-secret-key']);
    comparisonSeason();

    $this->get(route('players.compare', ['ids' => '1']).'&god_mode=super-secret-key')
        ->assertRedirect(route('players.compare', ['ids' => '1']))
        ->assertCookie('god_mode', '1');

    $this->withCookie('god_mode', '1')
        ->get(route('players.compare'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('godMode', true));
});
