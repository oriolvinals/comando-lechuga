<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    use AttachesActivityValueDifference;
    use ResolvesRequestedWeek;

    public function index(Request $request): Response
    {
        $season = Season::current();
        $week = $this->resolveWeek($request, $season);

        $fixtures = Fixture::query()
            ->with(['localTeam', 'guestTeam'])
            ->where('season_id', $season->id)
            ->where('week_number', $week)
            ->orderBy('date')
            ->get();

        $standings = SeasonTeam::query()
            ->where('season_id', $season->id)
            ->orderBy('position')
            ->get();

        $market = MarketPlayer::query()
            ->with(['player.team'])
            ->orderBy('expires_at')
            ->get();

        $activity = SeasonActivity::query()
            ->where('season_id', $season->id)
            ->with(['sourceSeasonTeam', 'targetSeasonTeam', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

        return Inertia::render('home', [
            'season' => $season,
            'filters' => ['week' => $week],
            'fixtures' => $fixtures,
            'standings' => $standings,
            'market' => $market,
            'activity' => $activity,
        ]);
    }
}
