<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Fixture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Fixture */
class FixtureDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => route('fixtures.show', $this->id),
            'date' => $this->date->toIso8601String(),
            'week_number' => $this->week_number,
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'display_clock' => $this->display_clock,
            'local_team' => new TeamResource($this->localTeam),
            'guest_team' => new TeamResource($this->guestTeam),
            'local_score' => $this->local_score,
            'guest_score' => $this->guest_score,
            'local_formation' => $this->local_formation,
            'guest_formation' => $this->guest_formation,
            'lineups' => FixtureLineupResource::collection($this->api_lineups),
            'events' => FixtureEventResource::collection($this->api_events),
            'team_stats' => $this->api_team_stats,
        ];
    }
}
