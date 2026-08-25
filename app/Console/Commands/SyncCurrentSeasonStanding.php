<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Season;
use App\Models\SeasonTeam;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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

                $fantasyId = (int) $teamData['id'];

                $seasonTeam = SeasonTeam::query()->firstOrNew([
                    'season_id' => $season->id,
                    'fantasy_id' => $fantasyId,
                ]);

                if (!$seasonTeam->exists) {
                    $seasonTeam->name = (string) $managerData['managerName'];
                }

                $seasonTeam->fill([
                    'fantasy_user_id' => (int) $managerData['id'],
                    'total_points' => (int) $standingData['points'],
                    'live_points' => isset($standingData['livePoints']) ? (int) $standingData['livePoints'] : null,
                    'position' => (int) $standingData['position'],
                    'last_position' => (int) $standingData['previousPosition'],
                    'value' => (int) $teamData['teamValue'],
                    'logo' => $this->resolveLogo($fantasyId),
                ])->save();

                $seasonTeamsSynchronized++;
            }

            return $seasonTeamsSynchronized;
        });

        $this->info($seasonTeamsSynchronized.' season teams synchronized.');

        return self::SUCCESS;
    }

    private function resolveLogo(int $fantasyId): string
    {
        $path = "images/teams/{$fantasyId}.png";

        return File::exists(public_path($path)) ? $path : '';
    }
}
