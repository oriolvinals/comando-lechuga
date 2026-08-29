<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Fixture;
use App\Models\ManagerLineup;
use App\Models\ManagerLineupPlayer;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
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

                DB::transaction(function () use ($season, $seasonManager, $weekNumber, $lineupData, $formation): void {
                    $lineup = ManagerLineup::query()->updateOrCreate(
                        [
                            'season_manager_id' => $seasonManager->id,
                            'week_number' => $weekNumber,
                        ],
                        [
                            'tactical_formation' => $formation['tacticalFormation'] ?? [],
                            'points' => (int) ($lineupData['points'] ?? 0),
                        ],
                    );

                    $syncedPlayerIds = [];

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

                            $fixture = Fixture::query()
                                ->where('season_id', $season->id)
                                ->where('week_number', $weekNumber)
                                ->where(fn ($query) => $query
                                    ->where('team_local_id', $player->team_id)
                                    ->orWhere('team_guest_id', $player->team_id))
                                ->first();

                            ManagerLineupPlayer::query()->updateOrCreate(
                                [
                                    'manager_lineup_id' => $lineup->id,
                                    'player_id' => $player->id,
                                ],
                                [
                                    'fixture_id' => $fixture?->id,
                                    'position' => $position,
                                ],
                            );

                            $syncedPlayerIds[] = $player->id;
                        }
                    }

                    ManagerLineupPlayer::query()
                        ->where('manager_lineup_id', $lineup->id)
                        ->whereNotIn('player_id', $syncedPlayerIds)
                        ->delete();
                });

                $lineupsSynchronized++;
            }
        }

        $this->info($lineupsSynchronized.' manager lineups synchronized.');

        return self::SUCCESS;
    }
}
