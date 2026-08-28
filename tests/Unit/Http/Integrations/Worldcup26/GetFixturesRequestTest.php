<?php

declare(strict_types=1);

use App\Http\Integrations\Worldcup26\Requests\GetFixturesRequest;
use Saloon\Enums\Method;

test('requests fixtures for the given page', function (): void {
    $request = new GetFixturesRequest(2);

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('get/soccer/esp.1/fixtures')
        ->and($request->query()->all())
        ->toBe(['status' => 'all', 'page' => 2]);
});

test('defaults to the first page', function (): void {
    $request = new GetFixturesRequest;

    expect($request->query()->all())
        ->toBe(['status' => 'all', 'page' => 1]);
});
