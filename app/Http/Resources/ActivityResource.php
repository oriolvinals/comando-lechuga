<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'source_season_manager' => [
                'id' => $this->sourceSeasonManager->id,
                'name' => $this->sourceSeasonManager->name,
            ],
            'target_season_manager' => $this->targetSeasonManager === null ? null : [
                'id' => $this->targetSeasonManager->id,
                'name' => $this->targetSeasonManager->name,
            ],
            'player' => $this->player === null ? null : [
                'id' => $this->player->id,
                'nickname' => $this->player->nickname,
            ],
            'amount' => $this->amount,
            'week_number' => $this->week_number,
            'value_difference' => $this->value_difference,
        ];
    }
}
