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

#[Signature('season:sync-current-match-data')]
#[Description('Catch-up sync (every ~15 min) for fixtures finished recently but outside the live sync window — late-arriving worldcup26/Fantasy corrections')]
class SyncCurrentSeasonMatchData extends Command
{
    use SyncsMatchData;

    private const int LIVE_WINDOW_HOURS = 4;

    private const int CATCH_UP_WINDOW_HOURS = 48;

    /**
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $worldcup26Connector, LaLigaFantasyConnector $fantasyConnector): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->where('date', '<', now()->subHours(self::LIVE_WINDOW_HOURS))
            ->where('date', '>=', now()->subHours(self::CATCH_UP_WINDOW_HOURS))
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
