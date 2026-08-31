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

#[Signature('season:sync-live-match-data')]
#[Description('Synchronize live/recently-finished fixtures\' state, lineups and events from worldcup26.ir')]
class SyncLiveSeasonMatchData extends Command
{
    use SyncsMatchData;

    private const int LIVE_WINDOW_HOURS = 4;

    /**
     * Worldcup26 can publish official lineups before kickoff — starting the
     * sync this early means we pick them up as soon as they're available
     * instead of waiting for the match to actually start.
     */
    private const int PRE_MATCH_WINDOW_HOURS = 1;

    /**
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $connector, LaLigaFantasyConnector $fantasyConnector): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->where('date', '<=', now()->addHours(self::PRE_MATCH_WINDOW_HOURS))
            ->where('date', '>=', now()->subHours(self::LIVE_WINDOW_HOURS))
            ->get();

        $result = $this->syncMatchDataForFixtures($fixtures, $connector, $fantasyConnector);

        $this->info("{$result['synced']} fixtures synced.");

        if ($result['unresolved'] !== []) {
            $message = 'Unresolved players — needs manual review: '.implode(', ', $result['unresolved']);
            $this->warn($message);
            Log::warning($message);
        }

        return self::SUCCESS;
    }
}
