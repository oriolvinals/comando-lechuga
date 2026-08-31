<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlayerPosition;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesRecentForm;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesCurrentPlayerSeason;
    use AttachesRecentForm;
    use AttachesRecentScores;
    use FiltersSeasonWeeks;
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

        $standings = SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('position')
            ->get();

        if (!$this->currentWeekIsLive($season)) {
            $standings->each(function (SeasonManager $manager): void {
                $manager->live_points = null;
            });
        }

        $this->attachRecentForm($standings, $season);

        $market = MarketPlayer::query()
            ->with(['player.team'])
            ->whereHas('player.seasons', fn ($query) => $query
                ->where('season_id', $season->id)
                ->where('position', '!=', PlayerPosition::Coach))
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        $this->attachCurrentSeason($market->pluck('player'), $season->id);
        $this->attachRecentScores($market->pluck('player'), $season);

        $activity = Activity::query()
            ->where('season_id', $season->id)
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

        return Inertia::render('home', [
            'season' => $season,
            'filters' => ['week' => $week],
            'fixtures' => $fixtures,
            'standings' => $standings,
            // Cast to object: PHP normalizes numeric string keys back to
            // int, so a plain array here could serialize as a sparse JSON
            // array instead of the {"1": "all", ...} object the frontend expects.
            'weekProgress' => (object) $this->weekProgress($season),
            'market' => $market,
            'activity' => $activity,
        ]);
    }
}
