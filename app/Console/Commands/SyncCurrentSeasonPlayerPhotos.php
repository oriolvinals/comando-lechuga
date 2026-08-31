<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Player;
use App\Models\Season;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-player-photos')]
#[Description('Synchronize player photos from La Liga Fantasy — split from season:sync-players since photos rarely change')]
class SyncCurrentSeasonPlayerPhotos extends Command
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
        $teamFantasyIds = $season->teams()->pluck('fantasy_id')->flip();
        $updated = 0;

        foreach ($connector->getPlayers()->throw()->json() as $playerData) {
            if (!$teamFantasyIds->has((int)$playerData['teamId'])) {
                continue;
            }

            if (($playerData['playerStatus'] ?? null) === PlayerStatus::OutOfLeague->value) {
                continue;
            }

            $image = $playerData['image'] ?? null;

            if (!is_string($image)) {
                continue;
            }

            $fantasyId = (int)$playerData['id'];

            try {
                $path = $this->storeImage($connector, $fantasyId, $image);
            } catch (FatalRequestException|RequestException $exception) {
                $message = "Failed to fetch photo for player {$fantasyId}: {$exception->getMessage()}";
                $this->warn($message);
                Log::warning($message);

                continue;
            }

            $updated += Player::query()
                ->where('fantasy_id', $fantasyId)
                ->update(['image' => $path]);
        }

        $this->info($updated.' player photos synchronized.');

        return self::SUCCESS;
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws Throwable
     */
    private function storeImage(LaLigaFantasyConnector $connector, int $fantasyId, string $imageUrl): string
    {
        $path = "images/player/$fantasyId.png";
        $contents = $connector->getAsset($imageUrl)->throw()->body();
        $disk = Storage::disk('public');

        if (!$disk->exists($path) || !hash_equals(hash('sha256', $disk->get($path)), hash('sha256', $contents))) {
            $disk->put($path, $contents);
        }

        return $path;
    }
}
