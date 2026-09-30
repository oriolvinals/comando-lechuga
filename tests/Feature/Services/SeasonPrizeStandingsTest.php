<?php

declare(strict_types=1);

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\SeasonPrizeStandings;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function currentPrizeSeason(int $currentWeek): Season
{
    return Season::factory()->create([
        'start_date' => now()->subDay(), 'end_date' => now()->addDay(),
        'current_week' => $currentWeek, 'total_weeks' => 38,
    ]);
}

test('builds the ten prizes in page order with every manager ranked', function (): void {
    $season = currentPrizeSeason(2);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'state' => FixtureState::Scheduled]);
    [$gau, $cid] = SeasonManager::factory()->count(2)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    ManagerLineup::factory()->create(['season_manager_id' => $gau->id, 'week_number' => 1, 'points' => 71]);
    ManagerLineup::factory()->create(['season_manager_id' => $cid->id, 'week_number' => 1, 'points' => 71]);

    $prizes = app(SeasonPrizeStandings::class)->forSeason($season)['prizes'];
    $noche = $prizes[0];

    expect(array_column($prizes, 'key'))->toHaveCount(10)
        ->and($noche['key'])->toBe('best_night')
        ->and($noche['leaders'])->toBe([$gau->id, $cid->id])
        ->and($noche['shares'])->toBe([$gau->id => 5.0, $cid->id => 5.0])
        ->and(array_column($noche['rows'], 'place'))->toBe([1, 1])
        ->and($prizes[9]['decided'])->toBeFalse()
        ->and($prizes[9]['rows'])->toBe([]);
});

test('before any finished jornada nobody leads anything', function (): void {
    $season = currentPrizeSeason(1);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'state' => FixtureState::FirstHalf]);
    SeasonManager::factory()->count(3)->create(['season_id' => $season->id]);

    $prizes = app(SeasonPrizeStandings::class)->forSeason($season)['prizes'];

    expect(array_merge(...array_column($prizes, 'leaders')))->toBe([]);
});

test('every tied most-owned player gives the prize to his own winner', function (): void {
    $season = currentPrizeSeason(3);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 1, 'date' => '2026-08-15 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 2, 'date' => '2026-08-22 19:00:00', 'state' => FixtureState::Finished]);
    Fixture::factory()->create(['season_id' => $season->id, 'week_number' => 3, 'date' => '2026-08-29 19:00:00', 'state' => FixtureState::Scheduled]);

    [$dubi, $cid, $cruza, $gau] = SeasonManager::factory()->count(4)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();
    [$first, $second] = Player::factory()->count(2)->create()->all();

    $move = fn (Player $player, SeasonActivityType $type, SeasonManager $source, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'player_id' => $player->id,
        'source_season_manager_id' => $source->id, 'target_season_manager_id' => null, 'occurred_at' => $at,
    ]);

    $move($first, SeasonActivityType::Sale, $dubi, '2026-08-10 10:00:00');
    $move($first, SeasonActivityType::Signing, $cid, '2026-08-11 20:00:00');
    $move($second, SeasonActivityType::Sale, $cruza, '2026-08-10 10:00:00');
    $move($second, SeasonActivityType::Signing, $gau, '2026-08-12 20:00:00');
    $move($second, SeasonActivityType::Sale, $gau, '2026-08-20 10:00:00');

    $standings = app(SeasonPrizeStandings::class)->forSeason($season);
    $mostOwned = collect($standings['prizes'])->firstWhere('key', 'most_owned_player');
    $places = collect($mostOwned['rows'])->pluck('place', 'season_manager_id');

    expect($mostOwned['leaders'])->toBe([$cid->id, $gau->id])
        ->and($mostOwned['shares'])->toBe([$cid->id => 2.5, $gau->id => 2.5])
        ->and($places[$cid->id])->toBe(1)
        ->and($places[$gau->id])->toBe(1)
        ->and($mostOwned['candidates'])->toHaveCount(2)
        ->and($standings['players'])->toHaveKeys([$first->id, $second->id]);
});

test('a finished sync command forgets the cached standings', function (): void {
    $season = currentPrizeSeason(1);
    Cache::put(SeasonPrizeStandings::cacheKey($season), ['stale'], 600);

    event(new CommandFinished('season:sync-standing', new ArrayInput([]), new NullOutput, 0));
    expect(Cache::has(SeasonPrizeStandings::cacheKey($season)))->toBeTrue();

    event(new CommandFinished('season:sync-activity', new ArrayInput([]), new NullOutput, 1));
    expect(Cache::has(SeasonPrizeStandings::cacheKey($season)))->toBeTrue();

    event(new CommandFinished('season:sync-activity', new ArrayInput([]), new NullOutput, 0));
    expect(Cache::has(SeasonPrizeStandings::cacheKey($season)))->toBeFalse();
});
