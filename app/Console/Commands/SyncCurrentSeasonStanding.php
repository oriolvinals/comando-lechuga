<?php

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Season;
use App\Models\SeasonTeam;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-standing')]
#[Description('Synchronize the current season standing from La Liga Fantasy')]
class SyncCurrentSeasonStanding extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(
        LaLigaLoginConnector $loginConnector,
        LaLigaFantasyConnector $fantasyConnector,
    ): int {
        $season = Season::current();
        $standing = $fantasyConnector
            ->getLeagueStandingWithLogin($loginConnector, $season->fantasy_id)
            ->json();
        $seasonTeamsSynchronized = DB::transaction(function () use ($season, $standing): int {
            $seasonTeamsSynchronized = 0;

            foreach ($standing as $standingData) {
                $teamData = $standingData['team'] ?? null;
                $managerData = is_array($teamData) ? $teamData['manager'] ?? null : null;

                if (!is_array($teamData) || !is_array($managerData)) {
                    continue;
                }

                SeasonTeam::query()->updateOrCreate(
                    [
                        'season_id' => $season->id,
                        'fantasy_id' => (int) $teamData['id'],
                    ],
                    [
                        'name' => (string) $managerData['managerName'],
                        'total_points' => (int) $standingData['points'],
                        'live_points' => (int) $standingData['livePoints'],
                        'position' => (int) $standingData['position'],
                        'last_position' => (int) $standingData['previousPosition'],
                        'value' => (int) $teamData['teamValue'],
                    ],
                );

                $seasonTeamsSynchronized++;
            }

            return $seasonTeamsSynchronized;
        });

        $this->info($seasonTeamsSynchronized.' season teams synchronized.');

        return self::SUCCESS;
    }
}
