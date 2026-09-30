<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\ElAtracador;
use App\Services\Prizes\LaVictima;
use App\Services\Prizes\PrizeRow;

function buyout(Season $season, SeasonManager $payer, SeasonManager $victim): void
{
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Buyout,
        'source_season_manager_id' => $payer->id,
        'target_season_manager_id' => $victim->id,
    ]);
}

test('counts clauses paid and received with the favourite victim and the nemesis', function (): void {
    $season = Season::factory()->create();
    [$duke, $dubi, $cid] = SeasonManager::factory()->count(3)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();

    buyout($season, $duke, $dubi);
    buyout($season, $duke, $dubi);
    buyout($season, $duke, $cid);
    buyout($season, $cid, $duke);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Signing, 'source_season_manager_id' => $duke->id]);

    $paid = collect(app(ElAtracador::class)->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);
    $received = collect(app(LaVictima::class)->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);

    expect($paid[$duke->id]->value)->toBe(3)
        ->and($paid[$duke->id]->context)->toBe(['favourite' => ['season_manager_id' => $dubi->id, 'count' => 2]])
        ->and($paid[$dubi->id]->value)->toBe(0)
        ->and($paid[$dubi->id]->context)->toBe(['favourite' => null])
        ->and($received[$dubi->id]->value)->toBe(2)
        ->and($received[$dubi->id]->context)->toBe(['nemesis' => ['season_manager_id' => $duke->id, 'count' => 2]])
        ->and($received[$duke->id]->value)->toBe(1);
});

test('the most repeated counterpart wins, the first seen on a tie, and missing counterparts are ignored', function (): void {
    $season = Season::factory()->create();
    [$duke, $dubi, $cid] = SeasonManager::factory()->count(3)->sequence(fn ($sequence) => ['season_id' => $season->id, 'position' => $sequence->index + 1])->create()->all();

    buyout($season, $duke, $cid);
    buyout($season, $duke, $dubi);
    Activity::factory()->create(['season_id' => $season->id, 'type' => SeasonActivityType::Buyout, 'source_season_manager_id' => $duke->id, 'target_season_manager_id' => null]);

    $paid = collect(app(ElAtracador::class)->rows($season))->keyBy(fn (PrizeRow $row): int => $row->seasonManagerId);

    expect($paid[$duke->id]->value)->toBe(3)
        ->and($paid[$duke->id]->context)->toBe(['favourite' => ['season_manager_id' => $cid->id, 'count' => 1]]);
});
