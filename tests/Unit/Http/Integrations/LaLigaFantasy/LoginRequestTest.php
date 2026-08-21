<?php

use App\Http\Integrations\LaLigaFantasy\Requests\LoginRequest;
use Saloon\Enums\Method;

test('sends the B2C login form', function () {
    $request = new LoginRequest(
        email: 'user@example.com',
        password: 'password',
        transaction: 'transaction',
        policy: 'B2C_1A_5ULAIP_PARAMETRIZED_SignIn',
        csrfToken: 'csrf-token',
        authorizeUrl: 'https://login.laliga.es/authorize',
    );

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('laligadspprob2c.onmicrosoft.com/B2C_1A_5ULAIP_PARAMETRIZED_SignIn/SelfAsserted')
        ->and($request->query()->all())
        ->toBe([
            'tx' => 'transaction',
            'p' => 'B2C_1A_5ULAIP_PARAMETRIZED_SignIn',
        ])
        ->and($request->body()->all())
        ->toBe([
            'request_type' => 'RESPONSE',
            'signInName' => 'user@example.com',
            'password_login' => 'password',
        ])
        ->and($request->headers()->all())
        ->toBe([
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'Origin' => 'https://login.laliga.es',
            'Referer' => 'https://login.laliga.es/authorize',
            'X-CSRF-TOKEN' => 'csrf-token',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);
});
