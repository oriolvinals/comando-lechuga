<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\PlayerSeason;
use Illuminate\Support\Collection;

/**
 * Writes DAZN estimates for a fixture's lineups, sharing the position lookup
 * (one query per fixture) between the live-sync recompute loop and the
 * one-off backfill command.
 */
final class DaznEstimateWriter
{
    public function __construct(private readonly DaznEstimator $estimator) {}

    /**
     * @param  Collection<int, FixtureLineup>  $lineups
     * @return int the number of lineup rows written
     */
    public function write(Fixture $fixture, Collection $lineups, bool $onlyMissing): int
    {
        $positionsByPlayer = PlayerSeason::query()
            ->where('season_id', $fixture->season_id)
            ->whereIn('player_id', $lineups->pluck('player_id'))
            ->get()
            ->mapWithKeys(fn (PlayerSeason $playerSeason): array => [$playerSeason->player_id => $playerSeason->position]);

        $written = 0;

        foreach ($lineups as $lineup) {
            if ($onlyMissing && $lineup->dazn_estimate !== null) {
                continue;
            }

            $estimate = $this->estimator->estimate($lineup, $fixture, $positionsByPlayer->get($lineup->player_id));

            $lineup->update([
                'dazn_estimate' => $estimate?->points,
                'dazn_estimate_version' => $estimate === null ? '' : DaznEstimator::VERSION,
                'dazn_estimate_meta' => $estimate?->toMeta(),
            ]);

            $written++;
        }

        return $written;
    }
}
