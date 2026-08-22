<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamLineup;
use App\Models\SeasonTeamLineupPlayer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-team-lineups')]
#[Description('Synchronize the current season team lineups from La Liga Fantasy')]
class SyncCurrentSeasonTeamLineups extends Command
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

        foreach (SeasonTeam::query()->where('season_id', $season->id)->get() as $seasonTeam) {
            for ($weekNumber = 1; $weekNumber <= $season->current_week; $weekNumber++) {
                $lineupData = $fantasyConnector
                    ->getTeamLineupWithLogin($loginConnector, $seasonTeam->fantasy_id, $weekNumber)
                    ->json();
                $formation = $lineupData['formation'] ?? null;

                if (!is_array($formation)) {
                    continue;
                }

                DB::transaction(function () use ($seasonTeam, $weekNumber, $lineupData, $formation): void {
                    $lineup = SeasonTeamLineup::query()->updateOrCreate(
                        [
                            'season_team_id' => $seasonTeam->id,
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

                            SeasonTeamLineupPlayer::query()->updateOrCreate(
                                [
                                    'season_team_lineup_id' => $lineup->id,
                                    'player_id' => $player->id,
                                ],
                                [
                                    'points' => isset($playerData['points']) ? (int) $playerData['points'] : null,
                                    'position' => $position,
                                ],
                            );
                        }
                    }
                });

                $lineupsSynchronized++;
            }
        }

        $this->info($lineupsSynchronized.' team lineups synchronized.');

        return self::SUCCESS;
    }
}
