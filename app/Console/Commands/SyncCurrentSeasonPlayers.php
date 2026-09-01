<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-players')]
#[Description('Synchronize the current season players from La Liga Fantasy')]
class SyncCurrentSeasonPlayers extends Command
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
        $teams = $season->teams()->get()->keyBy('fantasy_id');
        $players = [];

        $this->info('Fetching player list...');

        foreach ($connector->getPlayers()->throw()->json() as $playerData) {
            /** @var Team|null $team */
            $team = $teams->get((int)$playerData['teamId']);

            if ($team === null) {
                continue;
            }

            $position = PlayerPosition::fromFantasyId((int)$playerData['positionId']);

            // Coaches never appear in a worldcup26 match roster, so their
            // match_data_id can never be resolved, and we don't have the
            // Fantasy premium tier that would make their own stats useful.
            if ($position === PlayerPosition::Coach) {
                continue;
            }

            $players[] = [
                'fantasy_id' => (int)$playerData['id'],
                'nickname' => (string)$playerData['nickname'],
                'status' => PlayerStatus::from((string)$playerData['playerStatus']),
                'team_id' => $team->id,
                'position' => $position,
                'market_value' => (int)$playerData['marketValue'],
                'points' => (int)$playerData['points'],
                'average_points' => (float) $playerData['averagePoints'],
            ];
        }

        $this->info('Upserting '.count($players).' players...');

        $playerIds = DB::transaction(function () use ($players, $season): array {
            $playerIds = [];

            foreach ($players as $playerData) {
                $player = Player::query()->updateOrCreate(
                    ['fantasy_id' => $playerData['fantasy_id']],
                    [
                        'nickname' => $playerData['nickname'],
                        'status' => $playerData['status'],
                        'team_id' => $playerData['team_id'],
                    ],
                );

                PlayerSeason::query()->updateOrCreate(
                    ['player_id' => $player->id, 'season_id' => $season->id],
                    [
                        'position' => $playerData['position'],
                        'market_value' => $playerData['market_value'],
                        'points' => $playerData['points'],
                        'average_points' => $playerData['average_points'],
                    ],
                );

                $playerIds[] = $player->id;
            }

            return $playerIds;
        });

        $this->info(count($playerIds).' players synchronized.');

        return self::SUCCESS;
    }
}
