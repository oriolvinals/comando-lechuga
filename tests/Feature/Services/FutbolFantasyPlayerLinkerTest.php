<?php

declare(strict_types=1);

use App\Enums\FutbolFantasyLinkRule;
use App\Enums\PlayerPosition;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\Team;
use App\Services\FutbolFantasyAlternative;
use App\Services\FutbolFantasyPlayer;
use App\Services\FutbolFantasyPlayerLinker;

beforeEach(function (): void {
    $this->season = Season::factory()->create(['start_date' => now()->subMonth(), 'end_date' => now()->addMonths(8)]);
    $this->team = Team::factory()->create();
});

function linkerFfPlayer(int $id, string $name, string $slug = '', int $marketValue = 0, int $totalPoints = 0, ?PlayerPosition $position = null): FutbolFantasyPlayer
{
    return new FutbolFantasyPlayer(
        futbolfantasyId: $id,
        name: $name,
        slug: $slug,
        probability: 50,
        confirmedStarter: null,
        predictedStarter: true,
        rivalCode: 'VIL',
        marketValue: $marketValue,
        totalPoints: $totalPoints,
        position: $position,
    );
}

function marketValueOn(Player $player, int $value, int $daysAgo = 1): void
{
    PlayerMarket::factory()->create([
        'player_id' => $player->id,
        'date' => now()->subDays($daysAgo)->toDateString(),
        'value' => $value,
    ]);
}

test('links by the stored FútbolFantasy id first, even on another team', function (): void {
    $moved = Player::factory()->create(['futbolfantasy_id' => 7257, 'nickname' => 'Pedri']);
    $sameValue = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Otro']);
    marketValueOn($sameValue, 89_817_688);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(7257, 'Pedri', 'pedri-gonzalez', 89_817_688),
    ]);

    expect($links[7257]['player']->id)->toBe($moved->id)
        ->and($links[7257]['rule'])->toBe(FutbolFantasyLinkRule::StoredId)
        ->and($sameValue->refresh()->futbolfantasy_id)->toBeNull();
});

test('links a teammate by the exact market value of the last 3 days and stores the id', function (): void {
    $dumfries = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'D. Dumfries']);
    marketValueOn($dumfries, 26_446_951, daysAgo: 2);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(6055, 'Zzz', 'zzz', 26_446_951),
    ]);

    expect($links[6055]['player']->id)->toBe($dumfries->id)
        ->and($links[6055]['rule'])->toBe(FutbolFantasyLinkRule::MarketValue)
        ->and($dumfries->refresh()->futbolfantasy_id)->toBe(6055);
});

test('ignores market values older than 3 days', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Nadie']);
    marketValueOn($player, 26_446_951, daysAgo: 5);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(6055, 'Zzz', 'zzz', 26_446_951),
    ]);

    expect($links)->toBe([]);
});

test('breaks a market value tie by total points', function (): void {
    $low = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Uno', 'points' => 3]);
    $high = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Dos', 'points' => 12]);
    marketValueOn($low, 150_000);
    marketValueOn($high, 150_000);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(900, 'Zzz', 'zzz', 150_000, totalPoints: 12),
    ]);

    expect($links[900]['player']->id)->toBe($high->id)
        ->and($links[900]['rule'])->toBe(FutbolFantasyLinkRule::MarketValue);
});

test('breaks a market value and points tie by position', function (): void {
    $keeper = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Uno', 'points' => 0, 'position' => PlayerPosition::Goalkeeper]);
    $defender = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Dos', 'points' => 0, 'position' => PlayerPosition::Defender]);
    marketValueOn($keeper, 150_000);
    marketValueOn($defender, 150_000);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(901, 'Zzz', 'zzz', 150_000, totalPoints: 0, position: PlayerPosition::Defender),
    ]);

    expect($links[901]['player']->id)->toBe($defender->id);
});

test('links by normalised name when no market value matches', function (): void {
    $pedri = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Pedri']);
    $martinez = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'T. Martínez']);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(7257, 'Pedri', 'pedri-gonzalez'),
        linkerFfPlayer(3078, 'Toni Martinez', 'antonio-martinez'),
    ]);

    expect($links[7257]['player']->id)->toBe($pedri->id)
        ->and($links[7257]['rule'])->toBe(FutbolFantasyLinkRule::Name)
        ->and($links[3078]['player']->id)->toBe($martinez->id)
        ->and($martinez->refresh()->futbolfantasy_id)->toBe(3078);
});

test('leaves an ambiguous name unlinked', function (): void {
    Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'A. Rodríguez']);
    Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'M. Rodríguez']);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(4000, 'Rodríguez', 'adrian-rodriguez'),
    ]);

    expect($links)->toBe([]);
});

test('never links two FútbolFantasy players to the same one of ours', function (): void {
    $martinez = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'T. Martínez']);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(3078, 'Toni Martinez', 'antonio-martinez'),
        linkerFfPlayer(3079, 'Martinez', 'ismael-martinez'),
    ]);

    expect($links)->toHaveCount(1)
        ->and($links[3078]['player']->id)->toBe($martinez->id)
        ->and($links)->not->toHaveKey(3079);
});

test('never links a player of another team by market value or name', function (): void {
    $elsewhere = Player::factory()->create(['nickname' => 'Pedri']);
    marketValueOn($elsewhere, 89_817_688);

    $links = (new FutbolFantasyPlayerLinker([]))->link($this->team, $this->season, [
        linkerFfPlayer(7257, 'Pedri', 'pedri-gonzalez', 89_817_688),
    ]);

    expect($links)->toBe([]);
});

test('falls back to the manual map for leftovers', function (): void {
    $player = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Nombre Raro', 'fantasy_id' => 4242]);

    $links = (new FutbolFantasyPlayerLinker([17000 => 4242]))->link($this->team, $this->season, [
        linkerFfPlayer(17000, 'Mastantuono', 'franco-mastantuono'),
    ]);

    expect($links[17000]['player']->id)->toBe($player->id)
        ->and($links[17000]['rule'])->toBe(FutbolFantasyLinkRule::ManualMap)
        ->and($player->refresh()->futbolfantasy_id)->toBe(17000);
});

test('links an alternative through the page\'s own block with his slug', function (): void {
    $mbappe = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Mbappé', 'futbolfantasy_id' => 3429]);
    $page = [linkerFfPlayer(3429, 'Mbappé', 'kylian-mbappe')];
    $linker = new FutbolFantasyPlayerLinker([]);
    $links = $linker->link($this->team, $this->season, $page);

    $player = $linker->linkAlternative($this->team, new FutbolFantasyAlternative(1, 'K. Mbappé', 'kylian-mbappe'), $page, $links);

    expect($player?->id)->toBe($mbappe->id);
});

test('links an alternative missing from the page by a unique name on his team, without storing an id', function (): void {
    $diomande = Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'Y. Diomande']);
    Player::factory()->create(['nickname' => 'Y. Diomande']);

    $player = (new FutbolFantasyPlayerLinker([]))->linkAlternative($this->team, new FutbolFantasyAlternative(1, 'Y. Diomande', 'yan-diomande'), [], []);

    expect($player?->id)->toBe($diomande->id)
        ->and($diomande->refresh()->futbolfantasy_id)->toBeNull();
});

test('leaves an alternative unlinked when his name is ambiguous or unknown', function (): void {
    Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'A. Rodríguez']);
    Player::factory()->create(['team_id' => $this->team->id, 'nickname' => 'M. Rodríguez']);
    $linker = new FutbolFantasyPlayerLinker([]);

    expect($linker->linkAlternative($this->team, new FutbolFantasyAlternative(1, 'Rodríguez', 'rodriguez'), [], []))->toBeNull()
        ->and($linker->linkAlternative($this->team, new FutbolFantasyAlternative(1, 'Nadie', 'nadie-nadie'), [], []))->toBeNull();
});
