<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SeasonManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SeasonManager */
class StandingsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => route('api.managers.show', $this->id),
            'name' => $this->name,
            'logo' => $this->logo ? asset($this->logo) : '',
            'primary_color' => $this->primary_color,
            'secondary_color' => $this->secondary_color,
            'position' => $this->position,
            'last_position' => $this->last_position,
            'total_points' => $this->total_points,
            'value' => $this->value,
            'recent_form' => $this->api_recent_form,
        ];
    }
}
