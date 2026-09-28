<?php

declare(strict_types=1);

use App\Http\Integrations\FutbolFantasy\FutbolFantasyConnector;

test('identifies itself with an anonymous project User-Agent and asks for gzip', function (): void {
    $headers = (new FutbolFantasyConnector)->headers();

    expect($headers->get('User-Agent'))->toBe(FutbolFantasyConnector::USER_AGENT)
        ->and(FutbolFantasyConnector::USER_AGENT)->toStartWith('ComandoLechuga/')
        ->and(FutbolFantasyConnector::USER_AGENT)->not->toContain('@')
        ->and($headers->get('Accept-Encoding'))->toBe('gzip');
});
