<?php

declare(strict_types=1);

use App\Http\Middleware\AddApiResponseMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app stores its datetimes, but every API response must show them in
 * Europe/Madrid with the correct DST offset — +01:00 in winter, +02:00 in
 * summer — never the stored UTC `Z`. These exercise the middleware directly
 * with a literal UTC datetime so the conversion itself is pinned regardless
 * of how any given field happened to reach the response.
 */
test('converts a stored UTC datetime to Madrid winter time', function (): void {
    $middleware = new AddApiResponseMeta;
    $next = fn (Request $request): JsonResponse => response()->json([
        'data' => ['occurred_at' => '2026-01-15T12:00:00Z'],
    ]);

    $response = $middleware->handle(Request::create('/api/activity'), $next);

    expect($response->getData(true)['data']['occurred_at'])->toBe('2026-01-15T13:00:00+01:00');
});

test('converts a stored UTC datetime to Madrid summer time', function (): void {
    $middleware = new AddApiResponseMeta;
    $next = fn (Request $request): JsonResponse => response()->json([
        'data' => ['occurred_at' => '2026-07-15T12:00:00Z'],
    ]);

    $response = $middleware->handle(Request::create('/api/activity'), $next);

    expect($response->getData(true)['data']['occurred_at'])->toBe('2026-07-15T14:00:00+02:00');
});

test('leaves a bare date untouched', function (): void {
    $middleware = new AddApiResponseMeta;
    $next = fn (Request $request): JsonResponse => response()->json([
        'data' => ['date' => '2026-08-19'],
    ]);

    $response = $middleware->handle(Request::create('/api/players/1'), $next);

    expect($response->getData(true)['data']['date'])->toBe('2026-08-19');
});
