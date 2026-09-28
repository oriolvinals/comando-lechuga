<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\PlayerPosition;
use App\Http\Controllers\Controller;
use App\Http\Resources\PlayerResource;
use App\Models\MarketPlayer;
use App\Models\Season;
use App\Services\ApiPlayerShapes;
use Illuminate\Http\JsonResponse;

class MarketController extends Controller
{
    public function index(ApiPlayerShapes $playerShapes): JsonResponse
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

        $playerShapes->attach($listings->pluck('player'), $season);

        $data = $listings->map(fn (MarketPlayer $listing): array => [
            'player' => (new PlayerResource($listing->player))->resolve(),
            'sale_price' => $listing->sale_price,
            'market_value' => $listing->value,
            'bids' => $listing->bids,
            'expires_at' => $listing->expires_at->toIso8601String(),
            'seller' => MarketPlayer::SELLER_LEAGUE,
        ]);

        return response()->json(['data' => $data]);
    }
}
