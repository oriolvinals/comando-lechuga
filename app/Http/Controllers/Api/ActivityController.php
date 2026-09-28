<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\SeasonActivityType;
use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Concerns\ValidatesApiQuery;
use App\Http\Controllers\Controller;
use App\Http\Filters\ActivityFilter;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Season;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    use AttachesActivityValueDifference;
    use ValidatesApiQuery;

    public function index(Request $request, ActivityFilter $filter): AnonymousResourceCollection
    {
        $this->validateApiQuery($request, [
            'manager' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'player' => ['sometimes', 'nullable', $this->commaSeparatedIds()],
            'type' => ['sometimes', 'nullable', $this->commaSeparatedIn(array_map(
                fn (SeasonActivityType $type): string => $type->value,
                SeasonActivityType::cases(),
            ))],
        ]);

        $season = Season::current();

        $managerIds = $filter->getManagers();
        $types = $filter->getTypes();
        $playerIds = $filter->getPlayers();

        $activities = Activity::query()
            ->where('season_id', $season->id)
            ->when($managerIds !== [], fn ($query) => $query->where(
                fn ($query) => $query
                    ->whereIn('source_season_manager_id', $managerIds)
                    ->orWhereIn('target_season_manager_id', $managerIds),
            ))
            ->when($types !== [], fn ($query) => $query->whereIn('type', $types))
            ->when($playerIds !== [], fn ($query) => $query->whereIn('player_id', $playerIds))
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->paginate(30)
            ->withQueryString();

        $this->attachValueDifferences($activities->getCollection());

        return ActivityResource::collection($activities);
    }
}
