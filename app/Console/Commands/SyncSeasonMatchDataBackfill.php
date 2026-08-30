<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SyncsMatchData;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('season:sync-match-data-backfill')]
#[Description('Full daily safety-net sync (every played fixture in the season) for worldcup26/Fantasy match data')]
class SyncSeasonMatchDataBackfill extends Command
{
    use SyncsMatchData;

    /**
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $worldcup26Connector, LaLigaFantasyConnector $fantasyConnector): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->where('date', '<=', now())
            ->get();

        $result = $this->syncMatchDataForFixtures($fixtures, $worldcup26Connector, $fantasyConnector);

        $this->info("{$result['synced']} fixtures synced.");

        if ($result['unresolved'] !== []) {
            $message = 'Unresolved players — needs manual review: '.implode(', ', $result['unresolved']);
            $this->warn($message);
            Log::warning($message);
        }

        return self::SUCCESS;
    }
}
