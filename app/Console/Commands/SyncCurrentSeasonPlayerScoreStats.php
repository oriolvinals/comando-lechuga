<?php

declare(strict_types=1);

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

#[Signature('season:sync-player-score-stats')]
#[Description('Synchronize the detailed stats breakdown for the current season player scores')]
class SyncCurrentSeasonPlayerScoreStats extends Command
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
        $scoresUpdated = 0;

        foreach ($players as $player) {
            $playerData = $connector
                ->getPlayer($player->fantasy_id)
                ->throw()
                ->json();

            if (isset($playerData['points'])) {
                $player->update(['points' => (int) $playerData['points']]);
            }

            $playerStats = $playerData['playerStats'] ?? [];

            if (!is_array($playerStats)) {
                continue;
            }

            $scoresUpdated += DB::transaction(fn (): int => $this->updateStats($player->id, $playerStats));
        }

        $this->info($scoresUpdated.' player scores updated with stats.');

        return self::SUCCESS;
    }

    /**
     * @param  array<array-key, mixed>  $playerStats
     */
    private function updateStats(int $playerId, array $playerStats): int
    {
        $updated = 0;

        foreach ($playerStats as $scoreData) {
            if (!is_array($scoreData) || !isset($scoreData['weekNumber'])) {
                continue;
            }

            $stats = $scoreData['stats'] ?? [];

            if (!is_array($stats)) {
                continue;
            }

            $weekNumber = (int) $scoreData['weekNumber'];

            $updated += PlayerScore::query()
                ->where('player_id', $playerId)
                ->whereHas('fixture', fn ($query) => $query->where('week_number', $weekNumber))
                ->update([
                    'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
                    'ideal_formation' => (bool) ($scoreData['isInIdealFormation'] ?? false),
                ]);
        }

        return $updated;
    }
}
