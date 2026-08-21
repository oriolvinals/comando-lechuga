<?php

namespace App\Console\Commands;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\League;
use App\Models\Player;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('league:sync-players')]
#[Description('Synchronize the current league players from La Liga Fantasy')]
class SyncCurrentLeaguePlayers extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $league = League::query()
            ->where('current', true)
            ->sole();
        $teams = $league->teams()->get()->keyBy('fantasy_id');
        $players = [];

        foreach ($connector->getPlayers()->throw()->json() as $playerData) {
            $team = $teams->get((int) $playerData['teamId']);

            if ($team === null) {
                continue;
            }

            $fantasyId = (int) $playerData['id'];
            $image = $playerData['image'] ?? null;

            $players[] = [
                'fantasy_id' => $fantasyId,
                'position' => PlayerPosition::fromFantasyId((int) $playerData['positionId']),
                'nickname' => (string) $playerData['nickname'],
                'status' => PlayerStatus::from((string) $playerData['playerStatus']),
                'market_value' => (int) $playerData['marketValue'],
                'points' => (int) $playerData['points'],
                'average_points' => (float) $playerData['averagePoints'],
                'image' => $this->storeImage($connector, $fantasyId, is_string($image) ? $image : null),
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

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws Throwable
     */
    private function storeImage(LaLigaFantasyConnector $connector, int $fantasyId, ?string $imageUrl): string
    {
        if ($imageUrl === null) {
            return '';
        }

        $path = "images/player/$fantasyId.png";
        $contents = $connector->getAsset($imageUrl)->throw()->body();
        $disk = Storage::disk('public');

        if (! $disk->exists($path) || ! hash_equals(hash('sha256', $disk->get($path)), hash('sha256', $contents))) {
            $disk->put($path, $contents);
        }

        return $path;
    }
}
