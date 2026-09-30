<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\ClauseSnapshotSource;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ClauseRadar;
use App\Services\ManagerBalances;
use App\Services\ManagerShields;
use App\Services\Prizes\SquadHistory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The god-mode radar: cash ranges, squad values, totals and buyout clauses. PRIVATE: never mirrored in /api.
 *
 * @phpstan-type PickerPlayer array{id: int, nickname: string, image: string, position: string|null, team_short_name: string, team_logo: string}
 */
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
            'market' => $clauseRadar->marketRows($season, $balances, $connectedManagerId, $now),
            'manualRaises' => $this->manualRaises($season),
            'raiseCandidates' => $this->raiseCandidates($season),
            'now' => $now->toIso8601String(),
        ]);
    }

    /**
     * Every player each manager owned at some point this season, for the
     * manual raise picker: a raise can belong to a holding that already
     * ended. `current` marks the players he still owns.
     *
     * @return list<array{manager_id: int, player: PickerPlayer, current: bool}>
     */
    private function raiseCandidates(Season $season): array
    {
        $history = SquadHistory::forSeason($season);
        $players = $this->pickerPlayers($season, $history->playerIds());
        $candidates = [];

        foreach ($history->playerIds() as $playerId) {
            foreach ($history->spells($playerId) as $spell) {
                $key = "{$spell['season_manager_id']}:{$playerId}";
                $candidates[$key] = [
                    'manager_id' => $spell['season_manager_id'],
                    'player' => $players[$playerId],
                    'current' => ($candidates[$key]['current'] ?? false) || $spell['to'] === null,
                ];
            }
        }

        return array_values($candidates);
    }

    /**
     * The picker's card of each player: name, photo, club and position.
     *
     * @param  array<int, int>  $playerIds
     * @return array<int, PickerPlayer>
     */
    private function pickerPlayers(Season $season, array $playerIds): array
    {
        $positions = PlayerSeason::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $playerIds)
            ->get()
            ->mapWithKeys(fn (PlayerSeason $playerSeason): array => [$playerSeason->player_id => $playerSeason->position->value]);
        $players = [];

        foreach (Player::query()->with('team')->whereKey($playerIds)->get() as $player) {
            $players[$player->id] = [
                'id' => $player->id,
                'nickname' => $player->nickname,
                'image' => $player->image ? asset('storage/'.$player->image) : '',
                'position' => $positions[$player->id] ?? null,
                'team_short_name' => $player->team->short_name,
                'team_logo' => $player->team->logo ? asset('storage/'.$player->team->logo) : '',
            ];
        }

        return $players;
    }

    /**
     * The clause raises the user entered by hand this season, newest first.
     *
     * @return list<array{id: int, player: PickerPlayer, manager_id: int, captured_at: string, clause: int, raise: int, cost: int, note: string}>
     */
    private function manualRaises(Season $season): array
    {
        $entries = ManagerPlayerClauseSnapshot::query()
            ->where('source', ClauseSnapshotSource::Manual)
            ->whereHas('seasonManager', fn (Builder $query): Builder => $query->where('season_id', $season->id))
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get();
        $players = $this->pickerPlayers($season, $entries->map(fn (ManagerPlayerClauseSnapshot $entry): int => $entry->player_id)->unique()->values()->all());

        return array_values($entries
            ->map(fn (ManagerPlayerClauseSnapshot $entry): array => [
                'id' => $entry->id,
                'player' => $players[$entry->player_id],
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
