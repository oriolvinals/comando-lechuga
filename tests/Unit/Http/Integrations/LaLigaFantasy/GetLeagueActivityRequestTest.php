<?php

declare(strict_types=1);

use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueActivityRequest;
use Saloon\Enums\Method;

test('uses the authenticated league activity endpoint', function (): void {
    $request = new GetLeagueActivityRequest(
        leagueId: '017834818',
        page: 2,
        accessToken: 'access-token',
    );

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/leagues/017834818/activity/2')
        ->and($request->headers()->all())
        ->toBe([
            'Accept' => '*/*',
            'Authorization' => 'Bearer access-token',
            'X-Lang' => 'es',
            'X-Version' => '10.0.4',
            'X-App' => 'Fantasy-iOS',
        ]);
});

test('rejects a negative page', function (): void {
    expect(fn (): \App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueActivityRequest => new GetLeagueActivityRequest(
        leagueId: '017834818',
        page: -1,
        accessToken: 'access-token',
    ))->toThrow(InvalidArgumentException::class);
});

test('rejects an empty league ID or access token', function (): void {
    expect(fn (): \App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueActivityRequest => new GetLeagueActivityRequest(
        leagueId: '',
        page: 0,
        accessToken: 'access-token',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): \App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueActivityRequest => new GetLeagueActivityRequest(
            leagueId: '017834818',
            page: 0,
            accessToken: '',
        ))->toThrow(InvalidArgumentException::class);
});
