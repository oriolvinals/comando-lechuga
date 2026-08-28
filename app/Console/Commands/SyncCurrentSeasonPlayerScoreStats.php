<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\SyncsPlayerScoreStats;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-player-score-stats')]
#[Description('Synchronize the detailed stats breakdown for all current season player scores')]
class SyncCurrentSeasonPlayerScoreStats extends Command
{
    use SyncsPlayerScoreStats;

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
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->get();
        $scoresUpdated = 0;

        foreach ($players as $player) {
            $scoresUpdated += $this->syncPlayerScoreStats($player, $season, $connector);
        }

        $this->info($scoresUpdated.' player scores updated with stats.');

        return self::SUCCESS;
    }
}
