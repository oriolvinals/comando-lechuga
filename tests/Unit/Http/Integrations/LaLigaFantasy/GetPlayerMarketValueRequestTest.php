<?php

use App\Http\Integrations\LaLigaFantasy\Requests\GetPlayerMarketValueRequest;
use Saloon\Enums\Method;

test('uses the player market value endpoint', function () {
    $request = new GetPlayerMarketValueRequest(2783);

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/player/2783/market-value');
});
