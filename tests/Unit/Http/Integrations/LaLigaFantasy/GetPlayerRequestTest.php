<?php

use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerRequest;
use Saloon\Enums\Method;

test('uses the player detail endpoint', function () {
    $request = new GetPlayerRequest(2534);

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/player/2534');
});
