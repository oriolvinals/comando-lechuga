<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\AttachesActivityValueDifference;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityResource;
use App\Models\Activity;
use App\Models\Season;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ActivityController extends Controller
{
    use AttachesActivityValueDifference;

    public function index(): AnonymousResourceCollection
    {
        $season = Season::current();

        $activities = Activity::query()
            ->where('season_id', $season->id)
            ->with(['sourceSeasonManager', 'targetSeasonManager', 'player'])
            ->orderByDesc('occurred_at')
            ->paginate(30);

        $this->attachValueDifferences($activities->getCollection());

        return ActivityResource::collection($activities);
    }
}
