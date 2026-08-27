<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlayerPosition;
use App\Http\Controllers\Concerns\FiltersSeasonWeeks;
use App\Models\Fixture;
use App\Models\ManagerLineupPlayer;
use App\Models\PlayerScore;
use App\Models\Season;
use Inertia\Inertia;
use Inertia\Response;

class FixturesController extends Controller
{
    use FiltersSeasonWeeks;

    private const array POSITION_ORDER = [
        'goalkeeper' => 0,
        'defender' => 1,
        'midfield' => 2,
        'striker' => 3,
        'coach' => 4,
    ];

    public function index(): Response
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('week_number')
            ->orderBy('date')
            ->get();

        return Inertia::render('fixtures/index', [
            'season' => $season,
            'fixtures' => $fixtures,
            // Cast to object: PHP normalizes numeric string keys back to
            // int, so a plain array here could serialize as a sparse JSON
            // array instead of the {"1": "all", ...} object the frontend expects.
            'weekProgress' => (object) $this->weekProgress($season),
        ]);
    }

    public function show(Fixture $fixture): Response
    {
        $fixture->load(['localTeam', 'guestTeam']);

        $weekFixtures = Fixture::query()
            ->where('season_id', $fixture->season_id)
            ->where('week_number', $fixture->week_number)
            ->with(['localTeam', 'guestTeam'])
            ->orderBy('date')
            ->get();

        $scores = $fixture->playerScores()
            ->whereHas('player', fn ($query) => $query->where('position', '!=', PlayerPosition::Coach))
            ->with(['player', 'team'])
            ->get()
            ->sortByDesc('points')
            ->sortBy(fn ($score): int => self::POSITION_ORDER[$score->player->position->value])
            ->values();

        // Which manager fielded each player in their lineup this jornada — distinct
        // from ownership, since an owner can bench a player they still own.
        $lineupManagersByPlayer = ManagerLineupPlayer::query()
            ->whereIn('player_id', $scores->pluck('player_id'))
            ->whereHas('lineup', fn ($query) => $query
                ->where('week_number', $fixture->week_number)
                ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $fixture->season_id)))
            ->with('lineup.seasonManager')
            ->get()
            ->keyBy('player_id');

        $scores->each(function (PlayerScore $score) use ($lineupManagersByPlayer): void {
            $score->lineup_manager = $lineupManagersByPlayer->get($score->player_id)?->lineup?->seasonManager;
        });

        return Inertia::render('fixtures/show', [
            'fixture' => $fixture,
            'weekFixtures' => $weekFixtures,
            'scores' => $scores,
        ]);
    }
}
