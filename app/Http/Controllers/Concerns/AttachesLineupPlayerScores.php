<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Models\FixtureLineup;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Services\DaznEstimatePresenter;
use Illuminate\Database\Eloquent\Collection;

trait AttachesLineupPlayerScores
{
    /**
     * `stats` is looked up entirely from the linked `FixtureLineup` row (via
     * `fixture_id`, set once the lineup was synced). `points` prefers that
     * same row's `fantasy_points` but falls back to the raw `points` column
     * already loaded on the entry (set by SyncCurrentSeasonManagerLineups
     * from the Fantasy API directly) for a player who never resolves a
     * `fixture_id` — see `ManagerLineupPlayer::$points`. `starter`/
     * `subbed_out`/`sub_minute` ride along from that same row so the
     * frontend can badge a fantasy pick with their REAL match status
     * (titular/sustituido/suplente/banquillo) — null on all three when no
     * `FixtureLineup` resolves, which the frontend reads as "not called up"
     * once the match has finished, or simply "not played yet" otherwise.
     * All are attached as virtual properties, the same way
     * `attachMatchFinished` already does for `match_finished`. `dazn_points`,
     * `dazn_estimate`, `dazn_estimate_version`, `dazn_estimate_reasons` and
     * `dazn_estimate_source` are attached the same way, from
     * `DaznEstimatePresenter::present()` on that same linked `FixtureLineup`
     * (and its `fixture`) — see `ManagerLineupPlayer`'s docblock.
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
            ->with('fixture')
            ->get()
            ->keyBy(fn (FixtureLineup $lineup): string => "{$lineup->fixture_id}-{$lineup->player_id}");

        $entries->each(function (ManagerLineupPlayer $entry) use ($fixtureLineupsByKey): void {
            $fixtureLineup = $entry->fixture_id === null
                ? null
                : $fixtureLineupsByKey->get("{$entry->fixture_id}-{$entry->player_id}");

            $entry->points = $fixtureLineup->fantasy_points ?? $entry->points;
            $entry->stats = $fixtureLineup?->fantasy_stats;
            $entry->starter = $fixtureLineup?->starter;
            $entry->subbed_out = $fixtureLineup?->subbed_out;
            $entry->sub_minute = $fixtureLineup?->sub_minute;

            $dazn = $fixtureLineup !== null
                ? DaznEstimatePresenter::present($fixtureLineup, $fixtureLineup->fixture)
                : ['dazn_points' => null, 'dazn_estimate' => null, 'dazn_estimate_version' => '', 'dazn_estimate_reasons' => [], 'dazn_estimate_source' => null];

            $entry->dazn_points = $dazn['dazn_points'];
            $entry->dazn_estimate = $dazn['dazn_estimate'];
            $entry->dazn_estimate_version = $dazn['dazn_estimate_version'];
            $entry->dazn_estimate_reasons = $dazn['dazn_estimate_reasons'];
            $entry->dazn_estimate_source = $dazn['dazn_estimate_source'];
        });
    }
}
