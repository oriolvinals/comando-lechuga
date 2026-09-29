<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\FixtureState;
use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesDailyValueDifference;
use App\Http\Controllers\Concerns\AttachesLineupFixtures;
use App\Http\Controllers\Concerns\AttachesLineupPlayerScores;
use App\Http\Controllers\Concerns\AttachesMatchFinished;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Http\Resources\ManagerResource;
use App\Http\Resources\PlayerResource;
use App\Http\Resources\TeamResource;
use App\Models\Activity;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\ApiPlayerShapes;
use App\Services\LeagueStandings;
use App\Services\ManagerShields;
use App\Services\ManagerWeekRanks;
use App\Services\SeasonClock;
use App\Services\StartProbabilities;

class ManagerController extends Controller
{
    use AttachesActivityValueDifference;
    use AttachesCurrentPlayerSeason;
    use AttachesDailyValueDifference;
    use AttachesLineupFixtures;
    use AttachesLineupPlayerScores;
    use AttachesMatchFinished;

    public function __construct(
        private readonly ApiPlayerShapes $playerShapes,
        private readonly SeasonClock $clock,
        private readonly ManagerWeekRanks $managerWeekRanks,
        private readonly StartProbabilities $startProbabilities,
        private readonly ManagerShields $managerShields,
    ) {}

    public function show(SeasonManager $seasonManager): ManagerResource
    {
        $season = $seasonManager->season;
        $finishedWeeks = $this->clock->finishedWeekNumbers($season);

        if ($this->clock->weekState($season, $season->current_week) !== SeasonClock::LIVE) {
            $seasonManager->live_points = null;
        }

        $this->attachDailyValueDifference(collect([$seasonManager]), $season);
        $this->attachWeekRanks($seasonManager, $season, $finishedWeeks);
        $this->attachRoster($seasonManager, $season);
        $this->attachLineups($seasonManager, $season, $finishedWeeks);
        $this->attachRecentActivity($seasonManager, $season);

        $seasonManager->api_shields = ManagerShields::current(
            $this->managerShields->forSeason($season),
            $seasonManager->id,
        );

        return new ManagerResource($seasonManager);
    }

    /**
     * Rank per finished jornada, and the average over the jornadas the
     * manager actually had a lineup for (a manager who joined mid-season
     * isn't averaged over jornadas he didn't play).
     *
     * @param  list<int>  $finishedWeeks
     */
    private function attachWeekRanks(SeasonManager $seasonManager, Season $season, array $finishedWeeks): void
    {
        $weekRanks = $this->managerWeekRanks->forManager($seasonManager, $season, $finishedWeeks);
        $playedWeeks = count($weekRanks);

        $seasonManager->api_week_ranks = collect($weekRanks)
            ->map(fn (array $weekRank, int $weekNumber): array => ['week_number' => $weekNumber, ...$weekRank])
            ->values()
            ->all();
        $seasonManager->api_played_weeks = $playedWeeks;
        $seasonManager->api_average_points = $playedWeeks > 0
            ? round(array_sum(array_column($weekRanks, 'points')) / $playedWeeks, 2)
            : null;
    }

    private function attachRoster(SeasonManager $seasonManager, Season $season): void
    {
        $roster = ManagerPlayer::query()
            ->where('season_manager_id', $seasonManager->id)
            ->with('player.team')
            ->get();

        $players = $roster->pluck('player');
        $this->playerShapes->attach($players, $season);

        $purchases = Activity::query()
            ->where('season_id', $season->id)
            ->where('source_season_manager_id', $seasonManager->id)
            ->whereIn('player_id', $players->pluck('id'))
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Buyout])
            ->whereNotNull('amount')
            ->orderBy('occurred_at')
            ->get()
            ->keyBy('player_id');

        $seasonManager->api_roster = $roster->map(function (ManagerPlayer $entry) use ($purchases): array {
            $purchase = $purchases->get($entry->player_id);

            return [
                'player' => (new PlayerResource($entry->player))->resolve(),
                'purchase' => $purchase === null ? null : [
                    'amount' => $purchase->amount,
                    'type' => $purchase->type->value,
                    'occurred_at' => $purchase->occurred_at->toIso8601String(),
                ],
                'buyout_clause' => [
                    'amount' => $entry->buyout_clause,
                    'locked_until' => $entry->buyout_clause_locked_until->toIso8601String(),
                    'is_locked' => $entry->buyout_clause_locked_until->isFuture(),
                    'shielded' => $entry->shielded,
                    'shielded_until' => $entry->shielded_until?->toIso8601String(),
                ],
            ];
        })->all();
    }

    /**
     * `lineup_history` holds finished jornadas only. `current_lineup` is the
     * lineup of the jornada being played or next to play (see
     * SeasonClock::lineupWeek()); it is null without one.
     *
     * @param  list<int>  $finishedWeeks
     */
    private function attachLineups(SeasonManager $seasonManager, Season $season, array $finishedWeeks): void
    {
        $lineupWeek = $this->clock->lineupWeek($season);

        $lineups = ManagerLineup::query()
            ->where('season_manager_id', $seasonManager->id)
            ->whereIn('week_number', [...$finishedWeeks, $lineupWeek])
            ->with('players.player.team')
            ->orderBy('week_number')
            ->get();

        $this->attachCurrentSeason($lineups->flatMap(fn (ManagerLineup $lineup) => $lineup->players->pluck('player')), $season->id);
        $this->attachMatchFinished($lineups, $season);
        $this->attachLineupPlayerScores($lineups);
        $this->attachLineupFixtures($lineups, $season);

        $seasonManager->api_lineup_history = $lineups
            ->filter(fn (ManagerLineup $lineup): bool => in_array($lineup->week_number, $finishedWeeks, true))
            ->map(fn (ManagerLineup $lineup): array => $this->presentFinishedLineup($lineup))
            ->values()
            ->all();

        $currentLineup = in_array($lineupWeek, $finishedWeeks, true)
            ? null
            : $lineups->firstWhere('week_number', $lineupWeek);

        $seasonManager->api_current_lineup = $currentLineup instanceof ManagerLineup
            ? $this->presentCurrentLineup($currentLineup, $season)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFinishedLineup(ManagerLineup $lineup): array
    {
        return [
            'week_number' => $lineup->week_number,
            'points' => $lineup->points,
            'formation' => implode('-', $lineup->tactical_formation),
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
                'dazn_estimate' => $entry->dazn_estimate,
                'dazn_estimate_version' => $entry->dazn_estimate_version,
            ])->all(),
        ];
    }

    /**
     * Each pick's points only once his match has kicked off (live, then
     * final). `next_start` only applies while his match is still to be
     * played: it is null once the match kicks off, and when it is postponed.
     *
     * @return array<string, mixed>
     */
    private function presentCurrentLineup(ManagerLineup $lineup, Season $season): array
    {
        $nextStarts = $this->startProbabilities->forPlayersNextFixture($lineup->players->pluck('player'), $season);
        $kickedOffStates = [...LeagueStandings::LIVE_STATES, FixtureState::Finished];

        return [
            'week_number' => $lineup->week_number,
            'week_state' => $this->clock->weekState($season, $lineup->week_number),
            'formation' => implode('-', $lineup->tactical_formation),
            'tactical_formation' => $lineup->tactical_formation,
            'lineup_locks_at' => $this->clock->firstKickoff($season, $lineup->week_number)?->toIso8601String(),
            'points' => $lineup->points,
            'players' => $lineup->players->map(function (ManagerLineupPlayer $entry) use ($nextStarts, $kickedOffStates): array {
                $fixture = $entry->fixture;
                $kickedOff = $fixture !== null && in_array($fixture->state, $kickedOffStates, true);
                $nextStart = $nextStarts[$entry->player_id] ?? null;

                return [
                    'player' => [
                        'id' => $entry->player->id,
                        'url' => route('api.players.show', $entry->player->id),
                        'nickname' => $entry->player->nickname,
                        'image' => $entry->player->image ? asset('storage/'.$entry->player->image) : '',
                        'status' => $entry->player->status->value,
                        'position' => $entry->player->position?->value,
                        'team' => (new TeamResource($entry->player->team))->resolve(),
                    ],
                    'position' => $entry->position->value,
                    'match' => $fixture === null ? null : [
                        'fixture_id' => $fixture->id,
                        'url' => route('api.fixtures.show', $fixture->id),
                        'state' => $fixture->state->value,
                        'date' => $fixture->date->toIso8601String(),
                        'display_clock' => $fixture->display_clock,
                    ],
                    'points' => $kickedOff ? $entry->points : null,
                    'match_finished' => $entry->match_finished,
                    'next_start' => $fixture !== null && !$kickedOff && $nextStart !== null && $nextStart['fixture_id'] === $fixture->id
                        ? ApiPlayerShapes::presentNextStart($nextStart)
                        : null,
                ];
            })->all(),
        ];
    }

    private function attachRecentActivity(SeasonManager $seasonManager, Season $season): void
    {
        $activity = Activity::query()
            ->where('season_id', $season->id)
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
}
