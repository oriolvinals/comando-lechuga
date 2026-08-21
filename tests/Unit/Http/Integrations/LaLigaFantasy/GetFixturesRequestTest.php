<?php

declare(strict_types=1);

use App\Http\Integrations\LaLigaFantasy\Requests\GetFixturesRequest;
use Saloon\Enums\Method;

test('uses the calendar endpoint for the requested week', function (): void {
    $request = new GetFixturesRequest(1);

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/calendar')
        ->and($request->query()->all())
        ->toBe(['weekNumber' => 1]);
});
