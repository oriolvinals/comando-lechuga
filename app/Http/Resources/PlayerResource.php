<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Player */
class PlayerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => route('players.show', $this->id),
            'nickname' => $this->nickname,
            'image' => $this->image ? asset('storage/'.$this->image) : '',
            'status' => $this->status->value,
            'position' => $this->position?->value,
            'team' => new TeamResource($this->team),
            'market_value' => $this->market_value,
            'market_value_difference' => $this->market_value_difference,
            'points' => $this->points,
            'average_points' => $this->average_points,
            'owner_manager' => $this->owner_manager,
            'recent_scores' => $this->api_recent_scores,
            'next_fixtures' => $this->api_next_fixtures,
        ];
    }
}
