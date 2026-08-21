<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-week')]
#[Description('Synchronize the current season week from La Liga Fantasy')]
class SyncCurrentSeasonWeek extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException|Throwable
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $week = $connector->getCurrentWeek()->throw()->json();
        $season = Season::current();

        $season->update(['current_week' => (int) $week['weekNumber']]);

        $this->info('Week '.$season->current_week.' synchronized.');

        return self::SUCCESS;
    }
}
