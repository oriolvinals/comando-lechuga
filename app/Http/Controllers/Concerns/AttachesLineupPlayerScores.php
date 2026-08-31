<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use Illuminate\Database\Eloquent\Collection;

trait AttachesLineupPlayerScores
{
    /**
     * `stats` is looked up entirely from the linked `FixtureLineup` row (via
     * `fixture_id`, set once the lineup was synced). `points` prefers that
     * same row's `fantasy_points` but falls back to the raw `points` column
     * already loaded on the entry (set by SyncCurrentSeasonManagerLineups
     * from the Fantasy API directly) for a player who never resolves a
     * `fixture_id` — see `ManagerLineupPlayer::$points`. Both are attached as
     * virtual properties, the same way `attachMatchFinished` already does for
     * `match_finished`.
     *
     * This is a manual bulk lookup, not `ManagerLineupPlayer::fixtureLineup()`
     * eager-loaded via `->with()` — that relation is deliberately lazy-only
     * (see its docblock in `app/Models/ManagerLineupPlayer.php`, Task 5):
     * eager-loading it would bake only the first row's `fixture_id` into the
     * one shared query template Eloquent builds for the whole batch, silently
     * returning the wrong (or no) `FixtureLineup` for every other row.
     *
     * @param  Collection<int, ManagerLineup>  $lineups
     */
    private function attachLineupPlayerScores(Collection $lineups): void
    {
        $entries = $lineups->flatMap(fn (ManagerLineup $lineup) => $lineup->players);

        $fixtureLineupsByKey = FixtureLineup::query()
            ->whereIn('fixture_id', $entries->pluck('fixture_id')->filter()->unique())
            ->whereIn('player_id', $entries->pluck('player_id')->filter()->unique())
            ->get()
            ->keyBy(fn (FixtureLineup $lineup): string => "{$lineup->fixture_id}-{$lineup->player_id}");

        $entries->each(function (ManagerLineupPlayer $entry) use ($fixtureLineupsByKey): void {
            $fixtureLineup = $entry->fixture_id === null
                ? null
                : $fixtureLineupsByKey->get("{$entry->fixture_id}-{$entry->player_id}");

            $entry->points = $fixtureLineup->fantasy_points ?? $entry->points;
            $entry->stats = $fixtureLineup?->fantasy_stats;
        });
    }
}
