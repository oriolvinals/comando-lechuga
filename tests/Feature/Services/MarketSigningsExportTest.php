<?php

declare(strict_types=1);

use App\Enums\MarketTrend;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerBalanceSnapshot;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\Team;
use App\Services\MarketSigningsExport;
use Carbon\CarbonImmutable;

/**
 * One manager who bought De Haas at 23:30 Madrid on 1 October (app timezone Europe/Madrid, as in production), with
 * two daily values, an earlier signing and a private balance reading.
 *
 * @return array{season: Season, manager: SeasonManager, player: Player, signing: Activity}
 */
function marketSigningsWorld(): array
{
    config(['app.timezone' => 'Europe/Madrid']);
    date_default_timezone_set('Europe/Madrid');
    test()->travelTo(CarbonImmutable::parse('2026-10-02 10:00', 'Europe/Madrid'));

    $season = Season::factory()->create(['start_date' => '2026-08-01', 'end_date' => '2027-05-31']);
    $team = Team::factory()->create(['short_name' => 'VAL', 'main_name' => 'Valencia CF', 'logo' => 'images/team/18.png']);
    $player = Player::factory()->create([
        'nickname' => 'De Haas',
        'team_id' => $team->id,
        'image' => 'images/player/does-not-exist.png',
        'position' => 'defender',
        'market_trend' => MarketTrend::FallDecelerating,
        'points' => 12,
        'average_points' => 3,
    ]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-09-30', 'value' => 3_605_137]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-10-01', 'value' => 3_580_610]);
    PlayerMarket::factory()->create(['player_id' => $player->id, 'date' => '2026-10-02', 'value' => 3_500_000]);

    $manager = SeasonManager::factory()->create([
        'season_id' => $season->id,
        'name' => "  Comando   Lechuga \n",
        'logo' => 'images/managers/1.png',
        'primary_color' => '#ff0000',
        'position' => 2,
        'total_points' => 140,
    ]);
    Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id,
        'amount' => 1_000_000,
        'occurred_at' => CarbonImmutable::parse('2026-09-28 21:00', 'Europe/Madrid'),
    ]);
    $signing = Activity::factory()->create([
        'season_id' => $season->id,
        'type' => SeasonActivityType::Signing,
        'source_season_manager_id' => $manager->id,
        'player_id' => $player->id,
        'amount' => 6_780_610,
        'occurred_at' => CarbonImmutable::parse('2026-10-01 23:30', 'Europe/Madrid'),
    ]);
    ManagerBalanceSnapshot::factory()->create(['season_manager_id' => $manager->id, 'money' => 987_654_321]);

    return ['season' => $season, 'manager' => $manager, 'player' => $player, 'signing' => $signing];
}

afterEach(function (): void {
    date_default_timezone_set('UTC');
});

test('exports the research JSON for the given signings, keeping a 23:30 signing on its Madrid day', function (): void {
    ['manager' => $manager, 'player' => $player, 'signing' => $signing] = marketSigningsWorld();

    $data = json_decode(MarketSigningsExport::toJson((new MarketSigningsExport)->export('2026-10-01', collect([$signing]))), true);

    expect($data['date'])->toBe('2026-10-01')
        ->and($data['buys'])->toBe([[
            'id' => $signing->id,
            'amount' => 6_780_610,
            'buyer' => $manager->id,
            'value' => 3_580_610,
            'hist' => [3_605_137, 3_580_610],
            'valueDiff' => -24_527,
            'trend' => 'fall_decelerating',
            'player' => [
                'id' => $player->id,
                'name' => 'De Haas',
                'photo' => null,
                'pos' => 'DEF',
                'points' => 12,
                'avg' => 3,
                'team' => ['short' => 'VAL', 'name' => 'Valencia CF', 'logo' => '/storage/images/team/18.png'],
            ],
        ]])
        ->and($data['managers'])->toBe([(string) $manager->id => [
            'id' => $manager->id,
            'name' => 'Comando Lechuga',
            'logo' => '/images/managers/1.png',
            'color' => '#ff0000',
            'position' => 2,
            'total' => 140,
            'seasonSignings' => 2,
        ]])
        ->and($data['daily'])->toHaveCount(14)
        ->and($data['daily'][0])->toBe(['2026-09-18', 0, 0])
        ->and($data['daily'][10])->toBe(['2026-09-28', 1, 1_000_000])
        ->and($data['daily'][13])->toBe(['2026-10-01', 1, 6_780_610]);
});

test('the day bounds are the Madrid day in the app timezone', function (): void {
    config(['app.timezone' => 'UTC']);

    [$start, $end] = MarketSigningsExport::dayBounds('2026-10-01');

    expect($start->toIso8601String())->toBe('2026-09-30T22:00:00+00:00')
        ->and($end->toIso8601String())->toBe('2026-10-01T22:00:00+00:00');
});

test('the story export never carries private money, bid or god-mode data, nor times of day', function (): void {
    ['signing' => $signing] = marketSigningsWorld();
    $forbidden = [
        'team_money', 'teamMoney', 'money', 'balance', 'balances', 'cash', 'estimated_balance', 'daily_bonus',
        'max_bid', 'maxBid', 'bid', 'bids', 'bid_premium', 'projection', 'confidence', 'clause', 'buyout_clause',
        'radar', 'god', 'god_mode', 'at', 'occurred_at', 'time', 'hour',
    ];

    $json = MarketSigningsExport::toJson((new MarketSigningsExport)->export('2026-10-01', collect([$signing])));
    $data = json_decode($json, true);
    $collectKeys = function (mixed $value) use (&$collectKeys): array {
        return is_array($value)
            ? array_merge(array_filter(array_keys($value), 'is_string'), ...array_map($collectKeys, array_values($value)))
            : [];
    };

    expect(array_intersect($collectKeys($data), $forbidden))->toBe([])
        ->and($json)->not->toContain('987654321')
        ->and(preg_replace('/"exportedAt": "[^"]+"/', '', $json))->not->toMatch('/\b\d{2}:\d{2}\b/');
});
