<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\SummarizesFixtureStats;
use App\Http\Controllers\Controller;
use App\Http\Resources\FixtureDetailResource;
use App\Http\Resources\FixtureResource;
use App\Models\Fixture;
use App\Models\FixtureEvent;
use App\Models\FixtureLineup;
use App\Models\Season;
use Illuminate\Http\JsonResponse;

class FixturesController extends Controller
{
    use SummarizesFixtureStats;

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

    public function show(Fixture $fixture): FixtureDetailResource
    {
        $fixture->load(['localTeam', 'guestTeam']);

        $lineups = FixtureLineup::query()
            ->where('fixture_id', $fixture->id)
            ->with('player', 'counterpartPlayer')
            ->get();

        $lineups->each(function (FixtureLineup $lineup): void {
            $lineup->resolved_stats = $lineup->fantasy_stats ?? $this->worldcup26StatsFallback($lineup->stats);
        });

        $fixture->api_lineups = $lineups;
        $fixture->api_events = FixtureEvent::query()
            ->where('fixture_id', $fixture->id)
            ->with('player')
            ->orderBy('minute')
            ->get();
        $fixture->api_team_stats = $this->teamStats($lineups, $fixture);

        return new FixtureDetailResource($fixture);
    }
}
