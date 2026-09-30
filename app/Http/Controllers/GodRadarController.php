<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ClauseRadar;
use App\Services\ManagerBalances;
use App\Services\ManagerShields;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

/** The god-mode radar: cash ranges, squad values, totals and buyout clauses. PRIVATE: never mirrored in /api. */
class GodRadarController extends Controller
{
    public function show(ManagerBalances $managerBalances, ClauseRadar $clauseRadar, ManagerShields $managerShields): Response
    {
        $season = Season::current();
        $now = CarbonImmutable::now();
        $balances = $managerBalances->forSeason($season, $now);
        $connectedManagerId = $managerBalances->connectedManagerId($season);
        $shields = $managerShields->forSeason($season);

        $managers = SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('position')
            ->get()
            ->map(fn (SeasonManager $manager): array => [
                'id' => $manager->id,
                'name' => $manager->name,
                'logo' => $manager->logo ? asset($manager->logo) : '',
                'primary_color' => $manager->primary_color,
                'shields' => ManagerShields::current($shields, $manager->id),
                ...$balances[$manager->id]->toArray(),
            ])
            ->values()
            ->all();

        return Inertia::render('god/radar', [
            'connectedManagerId' => $connectedManagerId,
            'managers' => $managers,
            'clauses' => $clauseRadar->forSeason($season, $balances, $connectedManagerId, $now),
            'now' => $now->toIso8601String(),
        ]);
    }
}
