<?php

use App\Http\Integrations\LaLigaFantasy\Requests\GetTeamInfoRequest;
use Saloon\Enums\Method;

test('uses the teams statuses endpoint in Spanish', function () {
    $request = new GetTeamInfoRequest;

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('stats/v1/competition/1/players/status')
        ->and($request->query()->all())
        ->toBe(['x-lang' => 'es']);
});
