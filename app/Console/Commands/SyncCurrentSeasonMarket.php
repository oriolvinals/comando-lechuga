<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\MarketPlayer;
use App\Models\Player;
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

#[Signature('season:sync-market')]
#[Description('Synchronize the current league player market from La Liga Fantasy')]
class SyncCurrentSeasonMarket extends Command
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

        $this->info('Fetching league market...');
        $market = $fantasyConnector
            ->getLeagueMarketWithLogin($loginConnector, $season->fantasy_id)
            ->json();

        $marketPlayersSynchronized = DB::transaction(function () use ($market): int {
            $marketPlayersSynchronized = 0;

            foreach ($market as $marketData) {
                if (($marketData['discr'] ?? null) !== 'marketPlayerLeague') {
                    continue;
                }

                $playerData = $marketData['playerMaster'] ?? null;
                $expiresAt = $marketData['expiresAt'] ?? $marketData['expirationDate'] ?? null;

                if (!is_array($playerData) || !is_string($expiresAt)) {
                    continue;
                }

                $player = Player::query()
                    ->where('fantasy_id', (int) $playerData['id'])
                    ->first();

                if ($player === null) {
                    continue;
                }

                MarketPlayer::query()->updateOrCreate(
                    ['player_id' => $player->id],
                    [
                        'fantasy_id' => (int) $marketData['id'],
                        'expires_at' => CarbonImmutable::parse($expiresAt)
                            ->setTimezone((string) config('app.timezone')),
                        'bids' => (int) ($marketData['numberOfBids'] ?? 0),
                        'sale_price' => (int) $marketData['salePrice'],
                        'value' => (int) $playerData['marketValue'],
                    ],
                );

                $marketPlayersSynchronized++;
            }

            MarketPlayer::query()
                ->where('expires_at', '<', now())
                ->delete();

            return $marketPlayersSynchronized;
        });

        $this->info($marketPlayersSynchronized.' market players synchronized.');

        return self::SUCCESS;
    }
}
