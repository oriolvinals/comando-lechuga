<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-manager-players')]
#[Description('Synchronize the current squad of each season manager from La Liga Fantasy')]
class SyncCurrentSeasonManagerPlayers extends Command
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
        $managersSynchronized = 0;

        foreach (SeasonManager::query()->where('season_id', $season->id)->get() as $seasonManager) {
            $managerData = $fantasyConnector
                ->getLeagueTeamWithLogin($loginConnector, $season->fantasy_id, $seasonManager->fantasy_id)
                ->json();

            $players = $managerData['players'] ?? [];

            if (!is_array($players)) {
                continue;
            }

            DB::transaction(function () use ($seasonManager, $players): void {
                $currentPlayerIds = [];

                foreach ($players as $playerEntry) {
                    $playerMasterData = is_array($playerEntry) ? $playerEntry['playerMaster'] ?? null : null;

                    if (!is_array($playerMasterData)) {
                        continue;
                    }

                    $player = Player::query()
                        ->where('fantasy_id', (int) $playerMasterData['id'])
                        ->first();

                    if ($player === null) {
                        continue;
                    }

                    ManagerPlayer::query()->updateOrCreate(
                        [
                            'season_manager_id' => $seasonManager->id,
                            'player_id' => $player->id,
                        ],
                        [
                            'buyout_clause' => (int) ($playerEntry['buyoutClause'] ?? 0),
                            'buyout_clause_locked_until' => CarbonImmutable::parse((string) $playerEntry['buyoutClauseLockedEndTime'])
                                ->setTimezone((string) config('app.timezone')),
                            'shielded' => (bool) ($playerEntry['isShielded'] ?? false),
                            'shielded_until' => isset($playerEntry['shieldedEndDate'])
                                ? CarbonImmutable::parse((string) $playerEntry['shieldedEndDate'])
                                    ->setTimezone((string) config('app.timezone'))
                                : null,
                        ],
                    );

                    $currentPlayerIds[] = $player->id;
                }

                ManagerPlayer::query()
                    ->where('season_manager_id', $seasonManager->id)
                    ->whereNotIn('player_id', $currentPlayerIds)
                    ->delete();
            });

            $managersSynchronized++;
        }

        $this->info($managersSynchronized.' season manager squads synchronized.');

        return self::SUCCESS;
    }
}
