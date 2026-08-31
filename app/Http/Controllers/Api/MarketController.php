<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PlayerPosition;
use App\Http\Controllers\Concerns\AttachesApiNextFixtures;
use App\Http\Controllers\Concerns\AttachesApiRecentScores;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Http\Controllers\Concerns\AttachesOwnerManager;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerResource;
use App\Models\MarketPlayer;
use App\Models\Season;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    use AttachesApiNextFixtures;
    use AttachesApiRecentScores;
    use AttachesCurrentPlayerSeason;
    use AttachesOwnerManager;

    public function index(): JsonResponse
    {
        $season = Season::current();

        $listings = MarketPlayer::query()
            ->with(['player.team'])
            ->whereHas('player.seasons', fn ($query) => $query
                ->where('season_id', $season->id)
                ->where('position', '!=', PlayerPosition::Coach))
            ->where('expires_at', '>', now())
            ->orderBy('expires_at')
            ->get();

        $players = $listings->pluck('player');

        $this->attachOwnerManager($players, $season->id);
        $this->attachCurrentSeason($players, $season->id);
        $this->attachApiRecentScores($players, $season);
        $this->attachApiNextFixtures($players, $season);

        $data = $listings->map(fn (MarketPlayer $listing): array => [
            'player' => (new PlayerResource($listing->player))->resolve(),
            'sale_price' => $listing->sale_price,
            'value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }
}
