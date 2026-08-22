<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SeasonActivityType;
use App\Http\Filters\ActivityFilter;
use App\Models\PlayerMarket;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    public function index(ActivityFilter $filter): Response
    {
        $season = Season::current();

        $teamIds = $filter->getTeams();
        $types = $filter->getTypes();

        $activities = SeasonActivity::query()
            ->where('season_id', $season->id)
            ->when($teamIds !== [], fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereIn('source_season_team_id', $teamIds)
                    ->orWhereIn('target_season_team_id', $teamIds),
            ))
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->with(['sourceSeasonTeam', 'targetSeasonTeam', 'player'])
            ->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString();

        $this->attachValueDifferences($activities);

        $teams = SeasonTeam::query()
            ->where('season_id', $season->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('activity/index', [
            'activities' => $activities,
            'teams' => $teams,
            'filters' => [
                'team' => $teamIds,
                'type' => array_map(fn (SeasonActivityType $type): string => $type->value, $types),
            ],
        ]);
    }

    /**
     * @param  LengthAwarePaginator<int, SeasonActivity>  $activities
     */
    private function attachValueDifferences(LengthAwarePaginator $activities): void
    {
        /** @var Collection<int, SeasonActivity> $entries */
        $entries = $activities->getCollection();

        $eligible = $entries->filter(
            fn (SeasonActivity $activity): bool => $activity->player_id !== null && $activity->amount !== null,
        );

        $marketValues = $this->marketValuesByPlayerAndDate($eligible);

        $entries->each(function (SeasonActivity $activity) use ($marketValues): void {
            $playerId = $activity->player_id;
            $amount = $activity->amount;

            if ($playerId === null || $amount === null) {
                $activity->value_difference = null;

                return;
            }

            $market = $marketValues->get($playerId.'|'.$activity->occurred_at->toDateString());
            $activity->value_difference = $market !== null ? $amount - $market->value : null;
        });
    }

    /**
     * @param  Collection<int, SeasonActivity>  $eligible
     * @return Collection<string, PlayerMarket>
     */
    private function marketValuesByPlayerAndDate(Collection $eligible): Collection
    {
        if ($eligible->isEmpty()) {
            return collect();
        }

        return PlayerMarket::query()
            ->where(function (Builder $query) use ($eligible): void {
                foreach ($eligible as $activity) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->where('player_id', $activity->player_id)
                        ->whereDate('date', $activity->occurred_at->toDateString()));
                }
            })
            ->get()
            ->keyBy(fn (PlayerMarket $market): string => $market->player_id.'|'.$market->date->toDateString());
    }
}
