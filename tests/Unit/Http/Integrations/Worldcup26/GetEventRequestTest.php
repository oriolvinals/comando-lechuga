<?php

declare(strict_types=1);

use App\Http\Integrations\Worldcup26\Requests\GetEventRequest;
use Saloon\Enums\Method;

test('requests a single match by its worldcup26 id', function (): void {
    $request = new GetEventRequest(401882926);

    expect($request->getMethod())
        ->toBe(Method::GET)
        ->and($request->resolveEndpoint())
        ->toBe('get/soccer/esp.1/events/401882926');
});
