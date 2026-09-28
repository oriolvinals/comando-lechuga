<?php

declare(strict_types=1);

use App\Http\Integrations\FutbolFantasy\Requests\GetTeamPageRequest;
use Saloon\Enums\Method;

test('requests a team page by its FútbolFantasy slug', function (): void {
    $request = new GetTeamPageRequest('real-madrid');

    expect($request->getMethod())->toBe(Method::GET)
        ->and($request->resolveEndpoint())->toBe('laliga/equipos/real-madrid');
});
