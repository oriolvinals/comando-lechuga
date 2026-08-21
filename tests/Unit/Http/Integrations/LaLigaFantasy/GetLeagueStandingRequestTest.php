<?php

declare(strict_types=1);

use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueStandingRequest;
use Saloon\Enums\Method;

test('uses the authenticated league standing endpoint', function (): void {
    $request = new GetLeagueStandingRequest(
        leagueId: '017834818',
        accessToken: 'access-token',
    );

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/leagues/017834818/standing')
        ->and($request->headers()->all())
        ->toBe([
            'Accept' => '*/*',
            'Authorization' => 'Bearer access-token',
            'X-Lang' => 'es',
            'X-Version' => '10.0.4',
            'X-App' => 'Fantasy-iOS',
        ]);
});
