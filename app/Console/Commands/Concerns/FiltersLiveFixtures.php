<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Database\Eloquent\Builder;

trait FiltersLiveFixtures
{
    /**
     * Fixtures have no finished-at timestamp, so a recently finished match is approximated
     * as one that kicked off within this many hours (roughly a 2-hour match plus a 2-hour buffer).
     */
    private const int RECENTLY_FINISHED_WINDOW_HOURS = 4;

    /**
     * @return Builder<Fixture>
     */
    private function liveOrRecentlyFinishedFixtures(Season $season): Builder
    {
        $recentlyFinishedSince = now()->subHours(self::RECENTLY_FINISHED_WINDOW_HOURS);

        return Fixture::query()
            ->where('season_id', $season->id)
            ->where(function (Builder $query) use ($recentlyFinishedSince): void {
                $query
                    ->whereIn('state', [
                        FixtureState::FirstHalf,
                        FixtureState::HalfTime,
                        FixtureState::SecondHalf,
                    ])
                    ->orWhere(function (Builder $query) use ($recentlyFinishedSince): void {
                        $query
                            ->where('state', FixtureState::Finished)
                            ->where('date', '>=', $recentlyFinishedSince);
                    });
            });
    }
}
