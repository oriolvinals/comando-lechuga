<?php

declare(strict_types=1);

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\Prizes\MostOverpaid;
use App\Services\Prizes\PrizeRow;

test('sums what purchases and clauses paid above the market value of that day or the last one before', function (): void {
    $season = Season::factory()->create();
    $manager = SeasonManager::factory()->create(['season_id' => $season->id]);
    $camello = Player::factory()->create();
    $newcomer = Player::factory()->create();

    PlayerMarket::factory()->create(['player_id' => $camello->id, 'date' => '2026-08-10', 'value' => 30_000_000]);
    PlayerMarket::factory()->create(['player_id' => $camello->id, 'date' => '2026-08-20', 'value' => 50_000_000]);

    $deal = fn (SeasonActivityType $type, Player $player, int $amount, string $at) => Activity::factory()->create([
        'season_id' => $season->id, 'type' => $type, 'source_season_manager_id' => $manager->id,
        'target_season_manager_id' => $type === SeasonActivityType::Buyout ? SeasonManager::factory()->create(['season_id' => $season->id])->id : null,
        'player_id' => $player->id, 'amount' => $amount, 'occurred_at' => $at,
    ]);

    $deal(SeasonActivityType::Signing, $camello, 53_100_000, '2026-08-15 21:00:00');
    $deal(SeasonActivityType::Buyout, $camello, 49_000_000, '2026-08-21 10:00:00');
    $deal(SeasonActivityType::Signing, $newcomer, 90_000_000, '2026-08-21 10:00:00');
    $deal(SeasonActivityType::Sale, $camello, 99_000_000, '2026-08-22 10:00:00');

    $row = collect(app(MostOverpaid::class)->rows($season))->first(fn (PrizeRow $row): bool => $row->seasonManagerId === $manager->id);

    expect($row->value)->toBe(23_100_000)
        ->and($row->context)->toBe(['worst' => ['player_id' => $camello->id, 'overpaid' => 23_100_000]]);
});
