<?php

use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayersRequest;
use Saloon\Enums\Method;

test('uses the players endpoint', function () {
    $request = new GetPlayersRequest;

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/players');
});
