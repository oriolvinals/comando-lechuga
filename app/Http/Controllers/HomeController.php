<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesDailyValueDifference;
use App\Http\Controllers\Concerns\AttachesRecentForm;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Activity;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\JornadaMatches;
use App\Services\ManagerShields;
use App\Services\StartProbabilities;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesCurrentPlayerSeason;
    use AttachesDailyValueDifference;
    use AttachesRecentForm;
    use AttachesRecentScores;
    use FiltersSeasonWeeks;
    use ResolvesRequestedWeek;

    public function index(Request $request, JornadaMatches $jornadaMatches, StartProbabilities $startProbabilities, ManagerShields $managerShields): Response
    {
        $season = Season::current();
        $week = $this->resolveWeek($request, $season);

        $fixtures = Fixture::query()
            ->with(['localTeam', 'guestTeam'])
            ->where('season_id', $season->id)
            ->where('week_number', $week)
            ->orderBy('date')
            ->get();

        $nextFixture = Fixture::query()
            ->with(['localTeam', 'guestTeam'])
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Scheduled)
            ->where('date', '>', now())
            ->orderBy('date')
            ->first();

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
        $this->attachDailyValueDifference($standings, $season);

        $shields = $managerShields->forSeason($season);
        $standings->each(function (SeasonManager $manager) use ($shields): void {
            $manager->shields = ManagerShields::current($shields, $manager->id);
        });

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

        $nextStarts = $startProbabilities->forPlayersNextFixture($market->pluck('player'), $season);

        $market->each(function (MarketPlayer $listing) use ($nextStarts): void {
            $listing->player->next_start = $nextStarts[$listing->player->id] ?? null;
        });

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
            // The next kickoff of the season, independent of the jornada
            // browsed below — it drives the "Ahora" block's countdown.
            'nextFixture' => $nextFixture,
            // The current jornada's matches with the managers whose lineup
            // plays in each — the "Ahora" block's matches strip.
            'jornadaMatches' => $jornadaMatches->forSeason($season),
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
