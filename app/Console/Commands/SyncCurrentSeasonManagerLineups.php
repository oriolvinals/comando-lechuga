<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use App\Models\SeasonManagerLineup;
use App\Models\SeasonManagerLineupPlayer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-manager-lineups')]
#[Description('Synchronize the current season manager lineups from La Liga Fantasy')]
class SyncCurrentSeasonManagerLineups extends Command
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
        $lineupsSynchronized = 0;

        foreach (SeasonManager::query()->where('season_id', $season->id)->get() as $seasonManager) {
            for ($weekNumber = 1; $weekNumber <= $season->current_week; $weekNumber++) {
                $lineupData = $fantasyConnector
                    ->getTeamLineupWithLogin($loginConnector, $seasonManager->fantasy_id, $weekNumber)
                    ->json();
                $formation = $lineupData['formation'] ?? null;

                if (!is_array($formation)) {
                    continue;
                }

                DB::transaction(function () use ($seasonManager, $weekNumber, $lineupData, $formation): void {
                    $lineup = SeasonManagerLineup::query()->updateOrCreate(
                        [
                            'season_manager_id' => $seasonManager->id,
                            'week_number' => $weekNumber,
                        ],
                        [
                            'tactical_formation' => $formation['tacticalFormation'] ?? [],
                            'points' => (int) ($lineupData['points'] ?? 0),
                        ],
                    );

                    foreach (PlayerPosition::cases() as $position) {
                        $players = $formation[$position->value] ?? [];

                        if (!is_array($players)) {
                            continue;
                        }

                        foreach ($players as $lineupPlayerData) {
                            $playerData = $lineupPlayerData['playerMaster'] ?? null;

                            if (!is_array($playerData)) {
                                continue;
                            }

                            $player = Player::query()
                                ->where('fantasy_id', (int) $playerData['id'])
                                ->first();

                            if ($player === null) {
                                continue;
                            }

                            $lastStats = $playerData['lastStats'] ?? [];
                            $weekStats = is_array($lastStats)
                                ? Arr::first(
                                    $lastStats,
                                    fn ($stat): bool => is_array($stat) && ($stat['weekNumber'] ?? null) === $weekNumber,
                                )
                                : null;

                            SeasonManagerLineupPlayer::query()->updateOrCreate(
                                [
                                    'season_manager_lineup_id' => $lineup->id,
                                    'player_id' => $player->id,
                                ],
                                [
                                    'points' => is_array($weekStats) ? (int) ($weekStats['totalPoints'] ?? 0) : null,
                                    'stats' => is_array($weekStats) ? ($weekStats['stats'] ?? []) : null,
                                    'position' => $position,
                                ],
                            );
                        }
                    }
                });

                $lineupsSynchronized++;
            }
        }

        $this->info($lineupsSynchronized.' manager lineups synchronized.');

        return self::SUCCESS;
    }
}
