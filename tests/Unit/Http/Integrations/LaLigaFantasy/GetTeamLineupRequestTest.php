<?php

declare(strict_types=1);

use App\Http\Integrations\LaLigaFantasy\Requests\GetTeamLineupRequest;
use Saloon\Enums\Method;

test('uses the authenticated team lineup endpoint', function (): void {
    $request = new GetTeamLineupRequest(
        seasonTeamFantasyId: 37394771,
        weekNumber: 2,
        accessToken: 'access-token',
    );

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/teams/37394771/lineup/week/2')
        ->and($request->headers()->all())
        ->toBe([
            'Accept' => '*/*',
            'Authorization' => 'Bearer access-token',
            'X-Lang' => 'es',
            'X-Version' => '10.0.4',
            'X-App' => 'Fantasy-iOS',
        ]);
});
