<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Fixture;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Fixture */
class FixtureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'url' => route('api.fixtures.show', $this->id),
            'date' => $this->date->toIso8601String(),
            'state' => $this->state->value,
            'state_label' => $this->state->label(),
            'display_clock' => $this->display_clock,
            'local_team' => new TeamResource($this->localTeam),
            'guest_team' => new TeamResource($this->guestTeam),
            'local_score' => $this->local_score,
            'guest_score' => $this->guest_score,
        ];
    }
}
