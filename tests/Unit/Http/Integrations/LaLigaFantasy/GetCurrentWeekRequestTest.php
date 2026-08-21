<?php

use App\Http\Integrations\LaLigaFantasy\Requests\GetCurrentWeekRequest;
use Saloon\Enums\Method;

test('uses the current week endpoint', function () {
    $request = new GetCurrentWeekRequest;

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/week/current');
});
