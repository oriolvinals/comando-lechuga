<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
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

        foreach ($connector->getPlayers()->throw()->json() as $playerData) {
            /** @var Team|null $team */
            $team = $teams->get((int)$playerData['teamId']);

            if ($team === null) {
                continue;
            }

            $players[] = [
                'fantasy_id' => (int)$playerData['id'],
                'position' => PlayerPosition::fromFantasyId((int)$playerData['positionId']),
                'nickname' => (string)$playerData['nickname'],
                'status' => PlayerStatus::from((string)$playerData['playerStatus']),
                'market_value' => (int)$playerData['marketValue'],
                'points' => (int)$playerData['points'],
                'average_points' => (float) $playerData['averagePoints'],
                'team_id' => $team->id,
            ];
        }

        $playerIds = DB::transaction(function () use ($players): array {
            $playerIds = [];

            foreach ($players as $playerData) {
                $player = Player::query()->updateOrCreate(
                    ['fantasy_id' => $playerData['fantasy_id']],
                    $playerData,
                );

                $playerIds[] = $player->id;
            }

            return $playerIds;
        });

        $this->info(count($playerIds).' players synchronized.');

        return self::SUCCESS;
    }
}
