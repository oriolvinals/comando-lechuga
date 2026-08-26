<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FiltersLiveFixtures;
use App\Console\Commands\Concerns\SyncsPlayerScoreStats;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-live-player-score-stats')]
#[Description('Synchronize player score stats for players with a live or recently finished fixture')]
class SyncLiveSeasonPlayerScoreStats extends Command
{
    use FiltersLiveFixtures;
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

        $teamIds = $this->liveOrRecentlyFinishedFixtures($season)
            ->get(['team_local_id', 'team_guest_id'])
            ->flatMap(fn (Fixture $fixture): array => [$fixture->team_local_id, $fixture->team_guest_id])
            ->unique();

        $players = Player::query()
            ->whereIn('team_id', $teamIds)
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->get();
        $scoresUpdated = 0;

        foreach ($players as $player) {
            $scoresUpdated += $this->syncPlayerScoreStats($player, $connector);
        }

        $this->info($scoresUpdated.' player scores updated with stats.');

        return self::SUCCESS;
    }
}
