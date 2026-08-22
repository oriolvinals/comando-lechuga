<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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

        $schedule->command('season:sync-players')
            ->everyFiveMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-player-markets')
            ->everyTenMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-player-scores')
            ->everyFiveMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-team-lineups')
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
            ->everyTwoMinutes()
            ->runInBackground()
            ->withoutOverlapping()
            ->onOneServer();

        $schedule->command('season:sync-team-players')
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
