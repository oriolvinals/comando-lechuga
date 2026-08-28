<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\PlayerSeason;
use App\Models\Season;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

trait SyncsPlayerScoreStats
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    private function syncPlayerScoreStats(Player $player, Season $season, LaLigaFantasyConnector $connector): int
    {
        $playerData = $connector
            ->getPlayer($player->fantasy_id)
            ->throw()
            ->json();

        if (isset($playerData['points'])) {
            PlayerSeason::query()->updateOrCreate(
                ['player_id' => $player->id, 'season_id' => $season->id],
                ['points' => (int) $playerData['points']],
            );
        }

        $playerStats = $playerData['playerStats'] ?? [];

        if (!is_array($playerStats)) {
            return 0;
        }

        return DB::transaction(fn (): int => $this->updateStats($player->id, $playerStats));
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
                    ...isset($scoreData['totalPoints'])
                        ? ['points' => (int) $scoreData['totalPoints']]
                        : [],
                    'stats' => json_encode($stats, JSON_THROW_ON_ERROR),
                    'ideal_formation' => (bool) ($scoreData['isInIdealFormation'] ?? false),
                ]);
        }

        return $updated;
    }
}
