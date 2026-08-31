<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('season:sync-week')
            ->everyThirtyMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-teams')
            ->daily()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-fixtures')
            ->everyTwoMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:link-match-data-fixtures')
            ->everyFifteenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-live-match-data')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-current-match-data')
            ->everyFifteenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-match-data-backfill')
            ->dailyAt('00:00')
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-players')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-player-photos')
            ->daily()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-player-markets')
            ->everyFifteenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-manager-lineups')
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-market')
            ->everyTenSeconds()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-standing')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-activity')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-manager-players')
            ->everyMinute()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );

        // Renders our own Inertia error page for the handful of HTTP error
        // codes that have dedicated copy, in every environment (including
        // local) so it can actually be previewed while developing. Reuses
        // HandleInertiaRequests::share() directly since a route-not-found
        // 404 never runs the "web" middleware group, so season/liveMatchday
        // wouldn't otherwise reach the page.
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($request->is('api/*') || !in_array($response->getStatusCode(), [403, 404, 419, 429, 500, 503], true)) {
                return $response;
            }

            $props = [
                ...app(HandleInertiaRequests::class)->share($request),
                'status' => $response->getStatusCode(),
            ];

            return Inertia::render('errors/error', $props)
                ->toResponse($request)
                ->setStatusCode($response->getStatusCode());
        });
    })->create();
