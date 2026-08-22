<?php

declare(strict_types=1);

use App\Http\Integrations\LaLigaFantasy\Requests\GetWeekStatsRequest;
use Saloon\Enums\Method;

test('uses the week stats endpoint for the requested week', function (): void {
    $request = new GetWeekStatsRequest(2);

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('stats/v1/competition/1/stats/week/2');
});

test('rejects a non-positive week number', function (): void {
    new GetWeekStatsRequest(0);
})->throws(InvalidArgumentException::class);
