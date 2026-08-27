<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Filters\ActivityFilter;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonManager;
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

        $activities = SeasonActivity::query()
            ->where('season_id', $season->id)
            ->when($managerIds !== [], fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereIn('source_season_manager_id', $managerIds)
                    ->orWhereIn('target_season_manager_id', $managerIds),
            ))
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
            'filters' => [
                'manager' => $managerIds,
                'type' => array_map(fn (SeasonActivityType $type): string => $type->value, $types),
            ],
        ]);
    }
}
