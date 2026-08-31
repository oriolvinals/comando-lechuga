<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Http\JsonResponse;

class FixturesController extends Controller
{
    public function index(): JsonResponse
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('week_number')
            ->orderBy('date')
            ->get();

        $weeks = $fixtures->groupBy('week_number')
            ->map(fn ($weekFixtures, int $weekNumber): array => [
                'week_number' => $weekNumber,
                'fixtures' => FixtureResource::collection($weekFixtures)->resolve(),
            ])
            ->values();

        return response()->json(['data' => $weeks]);
    }
}
