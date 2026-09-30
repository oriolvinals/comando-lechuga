<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ClauseSnapshotSource;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ClauseRadar;
use App\Services\ManagerBalances;
use App\Services\ManagerShields;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
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
            'manualRaises' => $this->manualRaises($season),
            'now' => $now->toIso8601String(),
        ]);
    }

    /**
     * The clause raises the user entered by hand this season, newest first.
     *
     * @return list<array{id: int, player: array{id: int, nickname: string}, manager_id: int, captured_at: string, clause: int, raise: int, cost: int, note: string}>
     */
    private function manualRaises(Season $season): array
    {
        return array_values(ManagerPlayerClauseSnapshot::query()
            ->where('source', ClauseSnapshotSource::Manual)
            ->whereHas('seasonManager', fn (Builder $query): Builder => $query->where('season_id', $season->id))
            ->with('player:id,nickname')
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ManagerPlayerClauseSnapshot $entry): array => [
                'id' => $entry->id,
                'player' => ['id' => $entry->player_id, 'nickname' => $entry->player instanceof Player ? $entry->player->nickname : ''],
                'manager_id' => $entry->season_manager_id,
                'captured_at' => $entry->captured_at->toIso8601String(),
                'clause' => $entry->buyout_clause,
                'raise' => $entry->raise_amount,
                'cost' => intdiv($entry->raise_amount, 2),
                'note' => $entry->note,
            ])
            ->all());
    }
}
