<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SeasonManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SeasonManager */
class ManagerResource extends JsonResource
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
            'rank' => $this->position,
            'last_rank' => $this->last_position,
            'total_points' => $this->total_points,
            'live_points' => $this->live_points,
            'squad_value' => $this->value,
            'daily_value_difference' => $this->daily_value_difference,
            'played_weeks' => $this->api_played_weeks,
            'average_points' => $this->api_average_points,
            'week_ranks' => $this->api_week_ranks,
            'current_lineup' => $this->api_current_lineup,
            'lineup_history' => $this->api_lineup_history,
            'roster' => $this->api_roster,
            'recent_activity' => $this->api_recent_activity,
        ];
    }
}
