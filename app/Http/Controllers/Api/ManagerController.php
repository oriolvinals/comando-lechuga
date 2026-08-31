<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesLineupPlayerScores;
use App\Http\Controllers\Concerns\AttachesMatchFinished;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ManagerResource;
use App\Models\Activity;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;

class ManagerController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesCurrentPlayerSeason;
    use AttachesLineupPlayerScores;
    use AttachesMatchFinished;

    public function show(SeasonManager $seasonManager): ManagerResource
    {
        $season = $seasonManager->season;

        $this->attachRoster($seasonManager, $season);
        $this->attachLineupHistory($seasonManager, $season);
        $this->attachRecentActivity($seasonManager);

        return new ManagerResource($seasonManager);
    }

    private function attachRoster(SeasonManager $seasonManager, Season $season): void
    {
        $roster = ManagerPlayer::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('player.team')
            ->get();

        $this->attachCurrentSeason($roster->pluck('player'), $season->id);

        $seasonManager->api_roster = $roster->map(fn (ManagerPlayer $entry): array => [
            'player' => $this->playerSummary($entry->player),
            'buyout_clause' => $entry->buyout_clause,
            'buyout_clause_locked_until' => $entry->buyout_clause_locked_until->toIso8601String(),
            'shielded' => $entry->shielded,
            'shielded_until' => $entry->shielded_until?->toIso8601String(),
        ])->all();
    }

    private function attachLineupHistory(SeasonManager $seasonManager, Season $season): void
    {
        $lineupHistory = ManagerLineup::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('players.player.team')
            ->orderBy('week_number')
            ->get();

        $this->attachMatchFinished($lineupHistory, $season);
        $this->attachLineupPlayerScores($lineupHistory);

        $seasonManager->api_lineup_history = $lineupHistory->map(fn (ManagerLineup $lineup): array => [
            'week_number' => $lineup->week_number,
            'points' => $lineup->points,
            'tactical_formation' => $lineup->tactical_formation,
            'players' => $lineup->players->map(fn (ManagerLineupPlayer $entry): array => [
                'player' => [
                    'id' => $entry->player->id,
                    'nickname' => $entry->player->nickname,
                    'image' => $entry->player->image ? asset('storage/'.$entry->player->image) : '',
                ],
                'position' => $entry->position->value,
                'points' => $entry->points,
                'match_finished' => $entry->match_finished,
            ])->all(),
        ])->all();
    }

    private function attachRecentActivity(SeasonManager $seasonManager): void
    {
        $activity = Activity::query()
            ->where(fn ($query) => $query
                ->where('source_season_manager_id', $seasonManager->id)
                ->orWhere('target_season_manager_id', $seasonManager->id))
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get();

        $this->attachValueDifferences($activity);

        $seasonManager->api_recent_activity = ActivityResource::collection($activity)->resolve();
    }

    /**
     * @return array<string, mixed>
     */
    private function playerSummary(Player $player): array
    {
        return [
            'id' => $player->id,
            'nickname' => $player->nickname,
            'image' => $player->image ? asset('storage/'.$player->image) : '',
            'position' => $player->position?->value,
            'team' => [
                'id' => $player->team->id,
                'name' => $player->team->main_name,
                'logo' => $player->team->logo ? asset('storage/'.$player->team->logo) : '',
            ],
            'market_value' => $player->market_value,
            'points' => $player->points,
            'average_points' => $player->average_points,
        ];
    }
}
