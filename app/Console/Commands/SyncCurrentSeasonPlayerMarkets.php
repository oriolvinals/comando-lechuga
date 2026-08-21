<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-player-markets')]
#[Description('Synchronize the current season player markets from La Liga Fantasy')]
class SyncCurrentSeasonPlayerMarkets extends Command
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
            $markets = [];

            foreach ($connector->getPlayerMarketValue($player->fantasy_id)->throw()->json() as $marketData) {
                $markets[] = [
                    'fantasy_id' => (int)$marketData['lfpId'],
                    'player_id' => $player->id,
                    'date' => CarbonImmutable::parse($marketData['date'])->format('Y-m-d'),
                    'value' => (int)$marketData['marketValue'],
                ];
            }

            if ($markets === []) {
                continue;
            }

            usort($markets, static fn (array $left, array $right): int => $left['date'] <=> $right['date']);

            $lastIndex = count($markets) - 1;
            $difference = $lastIndex > 0
                ? $markets[$lastIndex]['value'] - $markets[$lastIndex - 1]['value']
                : 0;

            DB::transaction(function () use ($player, $markets, $difference): void {
                foreach ($markets as $marketData) {
                    PlayerMarket::query()->updateOrCreate(
                        [
                            'player_id' => $marketData['player_id'],
                            'date' => $marketData['date'],
                        ],
                        $marketData,
                    );
                }

                $player->update(['market_value_difference' => $difference]);
            });

            $playersSynchronized++;
        }

        $this->info($playersSynchronized.' player markets synchronized.');

        return self::SUCCESS;
    }
}
