<?php

use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueMarketRequest;
use Saloon\Enums\Method;

test('uses the authenticated league market endpoint', function () {
    $request = new GetLeagueMarketRequest(
        leagueId: '017834818',
        accessToken: 'access-token',
    );

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/league/017834818/market')
        ->and($request->headers()->all())
        ->toBe([
            'Accept' => '*/*',
            'Authorization' => 'Bearer access-token',
            'X-Lang' => 'es',
            'X-Version' => '10.0.4',
            'X-App' => 'Fantasy-iOS',
        ]);
});
