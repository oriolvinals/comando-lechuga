<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Activity;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\ManagerPlayer;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Services\SeasonClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A cheap hash of every input the prize calculators read, so the cached
 * standings are rebuilt only when one of them changed: the finished
 * jornadas and their fixtures (lineup locks), the managers' positions
 * (tie-breaks), the season's activity (squads, buyouts, prices), the
 * managers' lineups and lined-up players, the finished jornadas' player
 * points, the market values and the current squads. The tables carry no
 * timestamps, so each one is summarized by counts and id-weighted sums of
 * the columns the prizes use.
 */
final class PrizeDataFingerprint
{
    public function __construct(private readonly SeasonClock $clock) {}

    public function forSeason(Season $season): string
    {
        $finishedWeeks = $this->clock->finishedWeekNumbers($season);
        $managerIds = SeasonManager::query()->where('season_id', $season->id)->select('id');
        $finishedFixtureIds = Fixture::query()->where('season_id', $season->id)->whereIn('week_number', $finishedWeeks)->select('id');
        $finishedLineupIds = ManagerLineup::query()->whereIn('season_manager_id', $managerIds)->whereIn('week_number', $finishedWeeks)->select('id');

        $parts = [
            'weeks' => [$season->current_week, $finishedWeeks],
            'fixtures' => Fixture::query()
                ->whereIn('id', $finishedFixtureIds)
                ->orderBy('id')
                ->get(['id', 'date', 'state'])
                ->map(fn (Fixture $fixture): string => "{$fixture->id}|{$fixture->date->toDateTimeString()}|{$fixture->state->value}")
                ->all(),
            'managers' => $this->summary(
                SeasonManager::query()->where('season_id', $season->id),
                ['sum(cast(id as signed) * position)'],
            ),
            'activities' => $this->summary(
                Activity::query()->where('season_id', $season->id),
                [
                    'max(occurred_at)',
                    'sum(coalesce(amount, 0))',
                    'sum(cast(id as signed) * length(type))',
                    'sum(cast(id as signed) * coalesce(player_id, 0))',
                    'sum(cast(id as signed) * coalesce(source_season_manager_id, 0))',
                    'sum(cast(id as signed) * coalesce(target_season_manager_id, 0))',
                ],
            ),
            'lineups' => $this->summary(
                ManagerLineup::query()->whereIn('id', $finishedLineupIds),
                ['sum(cast(id as signed) * coalesce(points, 0))', 'sum(cast(id as signed) * week_number)'],
            ),
            'lineup_players' => $this->summary(
                ManagerLineupPlayer::query()->whereIn('manager_lineup_id', $finishedLineupIds),
                ['sum(player_id)'],
            ),
            'fixture_points' => $this->summary(
                FixtureLineup::query()->whereIn('fixture_id', $finishedFixtureIds)->whereNotNull('player_id'),
                ['sum(player_id)', 'sum(coalesce(fantasy_points, 0))', 'sum(cast(id as signed) * coalesce(fantasy_points, 0))'],
            ),
            'market_values' => $this->summary(
                PlayerMarket::query(),
                ['max(date)', 'sum(value)'],
            ),
            'squads' => $this->summary(
                ManagerPlayer::query()->whereIn('season_manager_id', $managerIds),
                ['sum(player_id)', 'sum(cast(season_manager_id as signed) * cast(player_id as signed))'],
            ),
        ];

        return md5((string) json_encode($parts));
    }

    /**
     * The row count, id total and the given aggregates of a query.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<literal-string>  $aggregates
     * @return list<mixed>
     */
    private function summary(Builder $query, array $aggregates): array
    {
        $base = $query->toBase()->selectRaw('count(*)')->selectRaw('sum(id)');

        foreach ($aggregates as $aggregate) {
            $base->selectRaw($aggregate);
        }

        return array_values((array) $base->first());
    }
}
