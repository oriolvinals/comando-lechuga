<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Filters\ActivityFilter;
use App\Models\Activity;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class ActivityController extends Controller
{
    use AttachesActivityValueDifference;

    public function index(ActivityFilter $filter): Response
    {
        $season = Season::current();

        $managerIds = $filter->getManagers();
        $types = $filter->getTypes();

        $activities = $this->managerFilteredQuery($season, $managerIds)
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString();

        $this->attachValueDifferences($activities->getCollection());

        $managers = SeasonManager::query()
            ->where('season_id', $season->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return Inertia::render('activity/index', [
            'activities' => $activities,
            'managers' => $managers,
            'typeCounts' => $this->typeCounts($season, $managerIds),
            'filters' => [
                'manager' => $managerIds,
                'type' => array_map(fn (SeasonActivityType $type): string => $type->value, $types),
            ],
        ]);
    }

    /**
     * The season's activity, narrowed to the given managers (as source or target) when any are given.
     *
     * @param  int[]  $managerIds
     * @return Builder<Activity>
     */
    private function managerFilteredQuery(Season $season, array $managerIds): Builder
    {
        return Activity::query()
            ->where('season_id', $season->id)
            ->when($managerIds !== [], fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereIn('source_season_manager_id', $managerIds)
                    ->orWhereIn('target_season_manager_id', $managerIds),
            ));
    }

    /**
     * Activity count per type across the whole manager-filtered set (not just the current page).
     * The type filter is deliberately ignored so the legend still shows the excluded types' counts.
     *
     * @param  int[]  $managerIds
     * @return array<string, int>
     */
    private function typeCounts(Season $season, array $managerIds): array
    {
        $counts = $this->managerFilteredQuery($season, $managerIds)
            ->toBase()
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        $typeCounts = [];

        foreach (SeasonActivityType::cases() as $type) {
            $typeCounts[$type->value] = (int) ($counts[$type->value] ?? 0);
        }

        return $typeCounts;
    }
}
