<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/**
 * Per finished jornada: the manager's squad at the lineup lock
 * (SeasonClock::lineupLock) minus the players he lined up; their fantasy
 * points that jornada (null = 0) add up. A jornada without the manager's
 * lineup is skipped. `top_miss` is the single biggest score left out, with
 * its fixture so the jornada sheet can open that match.
 *
 * @phpstan-type TopMiss array{fixture_lineup_id: int, fixture_id: int, player_id: int, week_number: int, points: int}
 */
final class BenchPoints implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    public function rows(Season $season): array
    {
        $history = SquadHistory::forSeason($season);
        $managerIds = $this->managerIds($season);
        $totals = array_fill_keys($managerIds, 0);

        /** @var array<int, TopMiss|null> $topMisses */
        $topMisses = array_fill_keys($managerIds, null);

        foreach ($this->clock->finishedWeekNumbers($season) as $week) {
            $lock = $this->clock->lineupLock($season, $week);

            if ($lock === null) {
                continue;
            }

            $lineups = ManagerLineup::query()
                ->with('players:id,manager_lineup_id,player_id')
                ->where('week_number', $week)
                ->whereIn('season_manager_id', $managerIds)
                ->get(['id', 'season_manager_id'])
                ->keyBy('season_manager_id');

            $scores = FixtureLineup::query()
                ->whereHas('fixture', fn ($query) => $query->where('season_id', $season->id)->where('week_number', $week))
                ->whereNotNull('player_id')
                ->get(['id', 'fixture_id', 'player_id', 'fantasy_points'])
                ->groupBy('player_id');

            foreach ($managerIds as $managerId) {
                $lineup = $lineups->get($managerId);

                if ($lineup === null) {
                    continue;
                }

                $linedUp = $lineup->players->pluck('player_id')->all();

                foreach (array_diff($history->squadAt($managerId, $lock), $linedUp) as $playerId) {
                    foreach ($scores->get($playerId, collect()) as $score) {
                        $points = (int) ($score->fantasy_points ?? 0);
                        $totals[$managerId] += $points;

                        if ($points > 0 && ($topMisses[$managerId] === null || $points > $topMisses[$managerId]['points'])) {
                            $topMisses[$managerId] = [
                                'fixture_lineup_id' => $score->id,
                                'fixture_id' => $score->fixture_id,
                                'player_id' => $playerId,
                                'week_number' => $week,
                                'points' => $points,
                            ];
                        }
                    }
                }
            }
        }

        return array_map(fn (int $id): PrizeRow => new PrizeRow($id, $totals[$id], ['top_miss' => $topMisses[$id]]), $managerIds);
    }
}
