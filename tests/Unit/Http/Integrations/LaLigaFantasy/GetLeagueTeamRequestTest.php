<?php

declare(strict_types=1);

use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueTeamRequest;
use Saloon\Enums\Method;

test('uses the authenticated league team endpoint', function (): void {
    $request = new GetLeagueTeamRequest(
        leagueId: '017834818',
        teamFantasyId: 37394521,
        accessToken: 'access-token',
    );

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('api/v1/competition/1/leagues/017834818/teams/37394521')
        ->and($request->headers()->all())
        ->toBe([
            'Accept' => '*/*',
            'Authorization' => 'Bearer access-token',
            'X-Lang' => 'es',
            'X-Version' => '10.0.4',
            'X-App' => 'Fantasy-iOS',
        ]);
});

test('rejects an empty league ID, an invalid team ID or an empty access token', function (): void {
    expect(fn (): \App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueTeamRequest => new GetLeagueTeamRequest(
        leagueId: '',
        teamFantasyId: 37394521,
        accessToken: 'access-token',
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): \App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueTeamRequest => new GetLeagueTeamRequest(
            leagueId: '017834818',
            teamFantasyId: 0,
            accessToken: 'access-token',
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): \App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueTeamRequest => new GetLeagueTeamRequest(
            leagueId: '017834818',
            teamFantasyId: 37394521,
            accessToken: '',
        ))->toThrow(InvalidArgumentException::class);
});
