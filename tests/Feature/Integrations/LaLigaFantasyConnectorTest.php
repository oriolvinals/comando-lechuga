<?php

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Http\Integrations\LaLigaFantasy\Requests\GetLeagueMarketRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

test('logs in and retries the market request after a forbidden response', function (): void {
    Cache::put('la_liga_fantasy.access_token', Crypt::encryptString('stale-token'), 3600);

    $accessToken = 'header.'.rtrim(strtr(base64_encode(json_encode([
        'exp' => now()->addHour()->timestamp,
    ], JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.signature';
    $loginConnector = Mockery::mock(LaLigaLoginConnector::class);
    $loginConnector->shouldReceive('accessToken')
        ->once()
        ->andReturn($accessToken);

    $fantasyConnector = (new LaLigaFantasyConnector)->withMockClient(new MockClient([
        GetLeagueMarketRequest::class => fn (PendingRequest $pendingRequest): MockResponse => $pendingRequest->headers()->get('Authorization') === 'Bearer stale-token'
            ? MockResponse::make([], 403)
            : MockResponse::make(['market' => true]),
    ]));

    $response = $fantasyConnector->getLeagueMarketWithLogin($loginConnector, '017834818');

    expect($response->json())->toBe(['market' => true])
        ->and(Crypt::decryptString(Cache::get('la_liga_fantasy.access_token')))->toBe($accessToken);
});
