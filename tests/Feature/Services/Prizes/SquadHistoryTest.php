<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\SquadHistory;
use Carbon\CarbonImmutable;

/**
 * DUBI starts with Vlachodimos (first move is his sale), CID buys him from
 * the market, Cruza pays his clause, then sells him. Remiro never moves:
 * he stays with Ariobretxa all season.
 *
 * @return array{Season, array<string, SeasonManager>, Player, Player}
 */
function squadHistorySeason(): array
{
    $season = Season::factory()->create();
    $managers = collect(['dubi', 'cid', 'cruza', 'ario'])
        ->mapWithKeys(fn (string $key): array => [$key => SeasonManager::factory()->create(['season_id' => $season->id])])
        ->all();
    $vlachodimos = Player::factory()->create();
    $remiro = Player::factory()->create();

    $move = fn (SeasonActivityType $type, string $source, ?string $target, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'player_id' => $vlachodimos->id,
        'source_season_manager_id' => $managers[$source]->id,
        'target_season_manager_id' => $target === null ? null : $managers[$target]->id,
        'occurred_at' => $at,
    ]);

    $move(SeasonActivityType::Sale, 'dubi', null, '2026-08-13 10:00:00');
    $move(SeasonActivityType::Signing, 'cid', null, '2026-08-14 20:00:00');
    $move(SeasonActivityType::Buyout, 'cruza', 'cid', '2026-08-27 09:00:00');
    $move(SeasonActivityType::Sale, 'cruza', null, '2026-08-27 18:00:00');

    ManagerPlayer::factory()->create(['season_manager_id' => $managers['ario']->id, 'player_id' => $remiro->id]);

    return [$season, $managers, $vlachodimos, $remiro];
}

test('replays the ownership spells of a player, the initial owner included', function (): void {
    [$season, $managers, $vlachodimos] = squadHistorySeason();

    $spells = SquadHistory::forSeason($season)->spells($vlachodimos->id);

    expect(array_column($spells, 'season_manager_id'))->toBe([$managers['dubi']->id, $managers['cid']->id, $managers['cruza']->id])
        ->and($spells[0]['from'])->toBeNull()
        ->and($spells[2]['to']?->toDateTimeString())->toBe('2026-08-27 18:00:00');
});

test('knows the squad at a known jornada lock', function (): void {
    [$season, $managers, $vlachodimos, $remiro] = squadHistorySeason();
    $history = SquadHistory::forSeason($season);
    $jornada2Lock = CarbonImmutable::parse('2026-08-22 19:00:00');

    expect($history->ownerAt($vlachodimos->id, $jornada2Lock))->toBe($managers['cid']->id)
        ->and($history->squadAt($managers['cid']->id, $jornada2Lock))->toBe([$vlachodimos->id])
        ->and($history->squadAt($managers['ario']->id, $jornada2Lock))->toBe([$remiro->id])
        ->and($history->ownerAt($vlachodimos->id, CarbonImmutable::parse('2026-08-14 20:00:00')))->toBe($managers['cid']->id)
        ->and($history->ownerAt($vlachodimos->id, CarbonImmutable::parse('2026-08-27 09:00:00')))->toBe($managers['cruza']->id)
        ->and($history->ownerAt($vlachodimos->id, CarbonImmutable::parse('2026-08-27 18:00:00')))->toBeNull()
        ->and($history->ownerAt($vlachodimos->id, CarbonImmutable::parse('2026-08-13 11:00:00')))->toBeNull();
});

test('the replayed present matches the current squads', function (): void {
    [$season, $managers, $vlachodimos, $remiro] = squadHistorySeason();
    $history = SquadHistory::forSeason($season);

    foreach ($managers as $manager) {
        expect($history->squadAt($manager->id, now()))
            ->toEqualCanonicalizing(ManagerPlayer::query()->where('season_manager_id', $manager->id)->pluck('player_id')->all());
    }
});

test('a late joiner owns the players he was allocated from joining, even ones released earlier', function (): void {
    $season = Season::factory()->create();
    [$cruza, $cid, $planuky] = SeasonManager::factory()->count(3)->create(['season_id' => $season->id])->all();
    $player = Player::factory()->create();

    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::JoinedLeague, 'player_id' => null,
        'source_season_manager_id' => $planuky->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-15 14:25:44']);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Sale, 'player_id' => $player->id,
        'source_season_manager_id' => $cruza->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-02 20:03:08']);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Buyout, 'player_id' => $player->id,
        'source_season_manager_id' => $cid->id, 'target_season_manager_id' => $planuky->id, 'occurred_at' => '2026-09-04 23:35:44']);

    $history = SquadHistory::forSeason($season);

    expect(array_column($history->spells($player->id), 'season_manager_id'))->toBe([$cruza->id, $planuky->id, $cid->id])
        ->and($history->spells($player->id)[1]['from']?->toDateTimeString())->toBe('2026-08-15 14:25:44')
        ->and($history->ownerAt($player->id, CarbonImmutable::parse('2026-08-10 12:00:00')))->toBeNull()
        ->and($history->ownerAt($player->id, CarbonImmutable::parse('2026-08-22 19:00:00')))->toBe($planuky->id);
});

test('a late joiner still holding a player released before he joined owns him since joining', function (): void {
    $season = Season::factory()->create();
    [$cruza, $planuky] = SeasonManager::factory()->count(2)->create(['season_id' => $season->id])->all();
    $player = Player::factory()->create();

    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::JoinedLeague, 'player_id' => null,
        'source_season_manager_id' => $planuky->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-15 14:25:44']);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Sale, 'player_id' => $player->id,
        'source_season_manager_id' => $cruza->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-02 20:03:08']);
    ManagerPlayer::factory()->create(['season_manager_id' => $planuky->id, 'player_id' => $player->id]);

    $history = SquadHistory::forSeason($season);

    expect(array_column($history->spells($player->id), 'season_manager_id'))->toBe([$cruza->id, $planuky->id])
        ->and($history->spells($player->id)[1]['from']?->toDateTimeString())->toBe('2026-08-15 14:25:44')
        ->and($history->squadAt($planuky->id, CarbonImmutable::parse('2026-08-22 19:00:00')))->toBe([$player->id])
        ->and($history->squadAt($planuky->id, now()))->toBe([$player->id]);
});

test('an allocation spell never starts after the player was given up', function (): void {
    $season = Season::factory()->create();
    $planuky = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();

    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::JoinedLeague, 'player_id' => null,
        'source_season_manager_id' => $planuky->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-15 14:25:44']);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Sale, 'player_id' => $player->id,
        'source_season_manager_id' => $planuky->id, 'target_season_manager_id' => null, 'occurred_at' => '2026-08-14 10:00:00']);

    $spell = SquadHistory::forSeason($season)->spells($player->id)[0];

    expect($spell['from']?->toDateTimeString())->toBe('2026-08-14 10:00:00')
        ->and($spell['to']?->toDateTimeString())->toBe('2026-08-14 10:00:00');
});

test('a manager who signs a player again opens a second spell', function (): void {
    $season = Season::factory()->create();
    $cid = SeasonManager::factory()->create(['season_id' => $season->id]);
    $player = Player::factory()->create();

    foreach ([[SeasonActivityType::Signing, '2026-08-14 20:00:00'], [SeasonActivityType::Sale, '2026-08-20 10:00:00'], [SeasonActivityType::Signing, '2026-08-25 20:00:00']] as [$type, $at]) {
        Activity::factory()->create(['season_id' => $season->id, 'type' => $type, 'player_id' => $player->id,
            'source_season_manager_id' => $cid->id, 'target_season_manager_id' => null, 'occurred_at' => $at]);
    }
    $history = SquadHistory::forSeason($season);

    expect(array_column($history->spells($player->id), 'season_manager_id'))->toBe([$cid->id, $cid->id])
        ->and($history->ownerAt($player->id, CarbonImmutable::parse('2026-08-22 19:00:00')))->toBeNull()
        ->and($history->ownerAt($player->id, CarbonImmutable::parse('2026-08-29 19:00:00')))->toBe($cid->id);
});
