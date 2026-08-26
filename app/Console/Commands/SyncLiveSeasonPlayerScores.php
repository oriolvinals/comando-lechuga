<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FiltersLiveFixtures;
use App\Console\Commands\Concerns\SyncsPlayerScores;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-live-player-scores')]
#[Description('Synchronize player scores for weeks with a live or recently finished fixture')]
class SyncLiveSeasonPlayerScores extends Command
{
    use FiltersLiveFixtures;
    use SyncsPlayerScores;

    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $season = Season::current();

        $weekNumbers = $this->liveOrRecentlyFinishedFixtures($season)
            ->pluck('week_number')
            ->unique()
            ->values()
            ->all();

        $scoresSynchronized = $this->syncPlayerScoresForWeeks($season, $weekNumbers, $connector);

        $this->info($scoresSynchronized.' player scores synchronized.');

        return self::SUCCESS;
    }
}
