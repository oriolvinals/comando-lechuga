<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\SeasonClock;
use App\Services\SeasonPrizeStandings;
use Inertia\Inertia;
use Inertia\Response;

class PrizesController extends Controller
{
    public function index(SeasonPrizeStandings $standings, SeasonClock $clock): Response
    {
        $season = Season::current();
        $computed = $standings->forSeason($season);
        $finishedWeeks = $clock->finishedWeekNumbers($season);

        return Inertia::render('prizes/index', [
            'season' => $season,
            'lastFinishedWeek' => $finishedWeeks === [] ? 0 : max($finishedWeeks),
            'managers' => SeasonManager::query()
                ->where('season_id', $season->id)
                ->orderBy('position')
                ->get(['id', 'name', 'logo', 'primary_color', 'position']),
            'prizes' => $computed['prizes'],
            // Cast to object: numeric keys would otherwise serialize as a sparse array.
            'players' => (object) $computed['players'],
        ]);
    }
}
