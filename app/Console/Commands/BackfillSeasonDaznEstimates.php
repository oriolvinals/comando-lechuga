<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FixtureState;
use App\Models\Fixture;
use App\Models\FixtureLineup;
use App\Models\Season;
use App\Services\DaznEstimateWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('season:backfill-dazn-estimates')]
#[Description('Estimate DAZN ratings for finished fixtures that have none yet')]
class BackfillSeasonDaznEstimates extends Command
{
    public function handle(DaznEstimateWriter $writer): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->where('state', FixtureState::Finished)
            ->get();

        $estimated = 0;
        $fixturesTouched = 0;

        foreach ($fixtures as $fixture) {
            $lineups = FixtureLineup::query()
                ->where('fixture_id', $fixture->id)
                ->whereNotNull('player_id')
                ->get();

            $written = $writer->write($fixture, $lineups, onlyMissing: true);

            if ($written > 0) {
                $estimated += $written;
                $fixturesTouched++;
            }

            if (DaznEstimateWriter::hasOfficialRating($lineups)) {
                $fixture->update(['dazn_published' => true]);
            }
        }

        $this->info("Estimated {$estimated} lineups across {$fixturesTouched} fixtures.");

        return self::SUCCESS;
    }
}
