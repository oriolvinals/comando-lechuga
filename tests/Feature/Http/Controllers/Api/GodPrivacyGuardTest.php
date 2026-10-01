<?php

declare(strict_types=1);

use App\Enums\ClauseSnapshotSource;
use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\PlayerDailySignal;
use App\Models\ValueForecast;
use App\Models\ValueForecastFit;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Http\Controllers\Api\ApiWorld;

/**
 * Every key, at any depth, of a decoded JSON body.
 *
 * @return list<string>
 */
function godPrivacyKeys(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        array_push($keys, ...godPrivacyKeys($item));
    }

    return $keys;
}

test('no api response ever carries private god-mode money or radar data', function (): void {
    $forbidden = [
        'team_money', 'teamMoney', 'money', 'balance', 'balances', 'cash', 'real_cash', 'is_real',
        'estimated_balance', 'activity_balance', 'daily_bonus', 'raises', 'clause_raises', 'snapshot', 'snapshots',
        'radar', 'payers', 'payer_level', 'opportunity', 'connected_manager_id', 'clause_snapshots', 'calibration', 'residual', 'raise_amount', 'manual_raises', 'manualRaises', 'raiseCandidates',
    ];
    $privateMoney = 987_654_321;

    $world = ApiWorld::seed();
    ManagerBalanceSnapshot::factory()->create([
        'season_manager_id' => $world->managerId,
        'money' => $privateMoney,
        'captured_at' => now()->subMinutes(5),
    ]);
    $privateClauseValues = ['876543219', '765432198', '654321987', 'nota-privada-subida'];
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $world->managerId, 'player_id' => $world->ownedPlayerId,
        'buyout_clause' => 876_543_219, 'captured_at' => now()->subDays(2),
    ]);
    ManagerPlayerClauseSnapshot::factory()->create([
        'season_manager_id' => $world->managerId, 'player_id' => $world->ownedPlayerId, 'source' => ClauseSnapshotSource::Manual,
        'buyout_clause' => 765_432_198, 'raise_amount' => 654_321_987, 'note' => 'nota-privada-subida', 'captured_at' => now()->subDay(),
    ]);
    $sampleIds = [
        'seasonManager' => $world->managerId,
        'fixture' => $world->finishedFixtureId,
        'player' => $world->ownedPlayerId,
    ];

    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/') && in_array('GET', $route->methods(), true));

    expect($apiRoutes)->not->toBeEmpty();

    foreach ($apiRoutes as $route) {
        $url = '/'.preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $match): string => (string) ($sampleIds[$match[1]] ?? throw new RuntimeException("No sample id for route parameter {$match[1]}")),
            $route->uri(),
        );

        $response = $this->getJson($url);

        $response->assertOk();
        expect(array_values(array_intersect(godPrivacyKeys($response->json()), $forbidden)))
            ->toBe([], "{$url} exposes a private god-mode field")
            ->and($response->getContent())
            ->not->toContain((string) $privateMoney, "{$url} leaks the real teamMoney");

        foreach ($privateClauseValues as $privateClauseValue) {
            expect($response->getContent())->not->toContain($privateClauseValue, "{$url} leaks the clause history");
        }
    }
});

test('the api docs never describe private god-mode data and keep saying the api has no cash balance', function (): void {
    $docs = (string) file_get_contents(resource_path('docs/api-docs.md'));

    foreach (['teamMoney', 'team_money', 'radar', 'snapshot', 'estimated_balance', 'oportunidad', 'payers'] as $term) {
        expect(mb_stripos($docs, $term))->toBeFalse("api-docs.md mentions {$term}");
    }

    expect($docs)->toContain('La API no tiene el saldo');
});

test('no api response ever carries the private value forecast or the daily signals', function (): void {
    $forbidden = [
        'predicted_value', 'up_probability', 'change_pct', 'impact_pct', 'target_date', 'day_one_forecast',
        'day_one_offset', 'value_forecast', 'valueForecast', 'forecast', 'next_difficulty',
    ];
    $privateForecast = 987_650_001;

    $world = ApiWorld::seed();
    ValueForecast::factory()->create([
        'season_id' => $world->seasonId,
        'player_id' => $world->ownedPlayerId,
        'reference_date' => now()->toDateString(),
        'target_date' => now()->addDay()->toDateString(),
        'predicted_value' => $privateForecast,
        'low' => $privateForecast - 1,
        'high' => $privateForecast + 1,
    ]);
    PlayerDailySignal::factory()->create([
        'season_id' => $world->seasonId,
        'player_id' => $world->ownedPlayerId,
        'next_difficulty' => 9.87,
    ]);
    $sampleIds = [
        'seasonManager' => $world->managerId,
        'fixture' => $world->finishedFixtureId,
        'player' => $world->ownedPlayerId,
    ];

    $apiRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with($route->uri(), 'api/') && in_array('GET', $route->methods(), true));

    foreach ($apiRoutes as $route) {
        $url = '/'.preg_replace_callback(
            '/\{(\w+)\}/',
            fn (array $match): string => (string) ($sampleIds[$match[1]] ?? throw new RuntimeException("No sample id for route parameter {$match[1]}")),
            $route->uri(),
        );

        $response = $this->getJson($url);

        $response->assertOk();
        expect(array_values(array_intersect(godPrivacyKeys($response->json()), $forbidden)))
            ->toBe([], "{$url} exposes a private value forecast field")
            ->and($response->getContent())
            ->not->toContain((string) $privateForecast, "{$url} leaks a forecast value");
    }
});

test('the api docs never describe the value forecast', function (): void {
    $docs = mb_strtolower((string) file_get_contents(resource_path('docs/api-docs.md')));

    foreach (['previsión del valor', 'prevision del valor', 'value forecast', 'value_forecast', 'forecast'] as $term) {
        expect(str_contains($docs, $term))->toBeFalse("api-docs.md mentions {$term}");
    }
});

// One arch() per target: an array of targets passed to a single expect() does not fail on a violation.
foreach (['App\Http\Controllers\Api', 'App\Http\Resources', 'App\Services\ApiPlayerShapes'] as $publicApiCode) {
    arch("{$publicApiCode} never reads the private value forecast or the daily signals")
        ->expect($publicApiCode)
        ->not->toUse([ValueForecast::class, ValueForecastFit::class, PlayerDailySignal::class, 'App\Services\ValueForecast']);
}
