<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Http\Controllers\Api\ApiWorld;

/**
 * Every key, at any depth, of a decoded JSON body.
 *
 * @return list<string>
 */
function maxBidGuardKeys(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $keys = [];

    foreach ($value as $key => $item) {
        if (is_string($key)) {
            $keys[] = $key;
        }

        array_push($keys, ...maxBidGuardKeys($item));
    }

    return $keys;
}

test('no api response ever carries a field of the private max bid model', function (): void {
    $forbidden = [
        'max_bid', 'bid', 'bid_premium', 'projection', 'projected_day7', 'projected_day14',
        'momentum_increment', 'market_adjustment', 'sport_adjustment', 'daily_increment',
        'sport_score', 'rivals_effect', 'upcoming_rivals', 'confidence', 'lock_days', 'reference_date',
        'day_one_forecast', 'day_one_offset',
    ];

    $world = ApiWorld::seed();
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
        expect(array_values(array_intersect(maxBidGuardKeys($response->json()), $forbidden)))
            ->toBe([], "{$url} exposes a max bid field");
    }
});
