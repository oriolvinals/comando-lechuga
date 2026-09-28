<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FixtureResource;
use App\Models\Season;
use App\Services\SeasonClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class SeasonController extends Controller
{
    public function show(SeasonClock $clock): JsonResponse
    {
        $season = Season::current();
        $now = CarbonImmutable::now();
        $upcomingWeek = $clock->upcomingWeek($season);
        $nextFixture = $clock->nextFixture($season, $now);

        return response()->json(['data' => [
            'name' => $season->name,
            'start_date' => $season->start_date->toDateString(),
            'end_date' => $season->end_date->toDateString(),
            'total_weeks' => $season->total_weeks,
            'current_week' => $season->current_week,
            'current_week_state' => $clock->weekState($season, $season->current_week),
            'upcoming_week' => $upcomingWeek === null ? null : [
                'week_number' => $upcomingWeek['week_number'],
                'lineup_locks_at' => $upcomingWeek['lineup_locks_at']->toIso8601String(),
                'buyouts_close_at' => $upcomingWeek['buyouts_close_at']->toIso8601String(),
                'buyouts_reopen_at' => $upcomingWeek['lineup_locks_at']->toIso8601String(),
            ],
            'buyouts_open' => $clock->buyoutsOpen($upcomingWeek, $now),
            'next_fixture' => $nextFixture === null ? null : (new FixtureResource($nextFixture))->resolve(),
            'next_market_renewal_at' => $clock->nextMarketRenewal($now)->toIso8601String(),
        ]]);
    }
}
