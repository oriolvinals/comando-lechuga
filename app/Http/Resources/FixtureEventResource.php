<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FixtureEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FixtureEvent */
class FixtureEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'minute' => $this->minute,
            'type' => $this->type,
            'team_id' => $this->team_id,
            'player' => $this->player === null ? null : [
                'id' => $this->player->id,
                'nickname' => $this->player->nickname,
            ],
            'unresolved_name' => $this->unresolved_name,
            'is_own_goal' => $this->is_own_goal,
            'is_penalty' => $this->is_penalty,
        ];
    }
}
