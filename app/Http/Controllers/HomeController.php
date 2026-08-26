<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\FixtureState;
use App\Enums\PlayerPosition;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Fixture;
use App\Models\MarketPlayer;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    use AttachesActivityValueDifference;
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

        $standings = SeasonTeam::query()
            ->where('season_id', $season->id)
            ->orderBy('position')
            ->get();

        $this->attachRecentForm($standings, $season);

        $market = MarketPlayer::query()
            ->with(['player.team'])
            ->whereHas('player', fn ($query) => $query->where('position', '!=', PlayerPosition::Coach))
            ->where('expires_at', '>', now())
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
            // Cast to object: PHP normalizes numeric string keys back to
            // int, so a plain array here could serialize as a sparse JSON
            // array instead of the {"1": "all", ...} object the frontend expects.
            'weekProgress' => (object) $this->weekProgress($season),
            'market' => $market,
            'activity' => $activity,
        ]);
    }

    /**
     * How far along each jornada is, for the week picker's coloring: 'none'
     * (no fixture finished yet), 'partial' (some but not all), or 'all'
     * (every fixture finished). Keyed by week number — cast to object at the
     * call site, since PHP normalizes a numeric string key back to int and
     * a plain array here could otherwise serialize as a sparse JSON array.
     *
     * @return array<int, 'none'|'partial'|'all'>
     */
    private function weekProgress(Season $season): array
    {
        return Fixture::query()
            ->where('season_id', $season->id)
            ->get(['week_number', 'state'])
            ->groupBy('week_number')
            ->mapWithKeys(function (Collection $fixtures, int $weekNumber): array {
                $finished = $fixtures->where('state', FixtureState::Finished)->count();

                $status = match (true) {
                    $finished === 0 => 'none',
                    $finished === $fixtures->count() => 'all',
                    default => 'partial',
                };

                return [$weekNumber => $status];
            })
            ->all();
    }

    /**
     * Attaches each team's points for its last 3 finished jornadas (oldest
     * first, left to right) — the "forma" column in the home standings. A
     * jornada still in progress is excluded, not just one that hasn't
     * started: its points are provisional, not a finished result. Shorter
     * than 3 — padded with null at the end — only for a team without 3
     * finished jornadas yet.
     *
     * @param  Collection<int, SeasonTeam>  $standings
     */
    private function attachRecentForm(Collection $standings, Season $season): void
    {
        $lineupsByTeam = SeasonTeamLineup::query()
            ->whereIn('season_team_id', $standings->pluck('id'))
            ->whereIn('week_number', $this->finishedWeekNumbers($season))
            ->get()
            ->groupBy('season_team_id');

        $standings->each(function (SeasonTeam $team) use ($lineupsByTeam): void {
            $recent = ($lineupsByTeam->get($team->id) ?? collect())
                ->sortByDesc('week_number')
                ->take(3)
                ->sortBy('week_number')
                ->values()
                ->map(fn (SeasonTeamLineup $lineup): int => $lineup->points)
                ->all();

            /** @var array<int, int|null> $paddedRecent */
            $paddedRecent = array_pad($recent, 3, null);
            $team->recent_form = $paddedRecent;
        });
    }
}
