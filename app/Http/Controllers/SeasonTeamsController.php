<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesRecentScores;
use App\Http\Controllers\Concerns\ResolvesRequestedWeek;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamPlayer;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SeasonTeamsController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesRecentScores;
    use ResolvesRequestedWeek;

    public function index(Request $request): Response
    {
        $season = Season::current();
        $week = $this->resolveWeek($request, $season);

        $lineups = SeasonTeamLineup::query()
            ->where('week_number', $week)
            ->whereHas('seasonTeam', fn ($query) => $query->where('season_id', $season->id))
            ->with(['seasonTeam', 'players.player.team'])
            ->orderByDesc('points')
            ->get();

        return Inertia::render('season-teams/index', [
            'season' => $season,
            'filters' => ['week' => $week],
            'lineups' => $lineups,
        ]);
    }

    public function show(SeasonTeam $seasonTeam): Response
    {
        $season = Season::current();

        $roster = SeasonTeamPlayer::query()
            ->where('season_team_id', $seasonTeam->id)
            ->with('player.team')
            ->get();

        $this->attachRecentScores($roster->pluck('player'), $season);

        $lineupHistory = SeasonTeamLineup::query()
            ->where('season_team_id', $seasonTeam->id)
            ->with('players.player.team')
            ->orderByDesc('week_number')
            ->get();

        $activity = SeasonActivity::query()
            ->where(fn ($query) => $query
                ->where('source_season_team_id', $seasonTeam->id)
                ->orWhere('target_season_team_id', $seasonTeam->id))
            ->with(['sourceSeasonTeam', 'targetSeasonTeam', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

        return Inertia::render('season-teams/show', [
            'season' => $season,
            'seasonTeam' => $seasonTeam,
            'roster' => $roster,
            'lineupHistory' => $lineupHistory,
            'activity' => $activity,
        ]);
    }
}
