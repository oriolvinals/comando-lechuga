<?php

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-player-scores')]
#[Description('Synchronize the current season player scores from La Liga Fantasy')]
class SyncCurrentSeasonPlayerScores extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $season = Season::current();
        $players = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->get();
        $playersSynchronized = 0;

        foreach ($players as $player) {
            $playerStats = $connector
                ->getPlayer($player->fantasy_id)
                ->throw()
                ->json('playerStats', []);

            if (!is_array($playerStats)) {
                continue;
            }

            DB::transaction(function () use ($player, $playerStats): void {
                foreach ($playerStats as $scoreData) {
                    if (!is_array($scoreData) || !isset($scoreData['weekNumber'])) {
                        continue;
                    }

                    $stats = $scoreData['stats'] ?? [];

                    if (!is_array($stats)) {
                        continue;
                    }

                    PlayerScore::query()->updateOrCreate(
                        [
                            'player_id' => $player->id,
                            'week_number' => (int) $scoreData['weekNumber'],
                        ],
                        [
                            'points' => (int) ($scoreData['totalPoints'] ?? 0),
                            'stats' => $stats,
                            'ideal_formation' => (bool) ($scoreData['isInIdealFormation'] ?? false),
                        ],
                    );
                }
            });

            $playersSynchronized++;
        }

        $this->info($playersSynchronized.' player scores synchronized.');

        return self::SUCCESS;
    }
}
