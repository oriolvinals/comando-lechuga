<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FixtureLineup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FixtureLineup */
class FixtureLineupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'player' => $this->player === null ? null : [
                'id' => $this->player->id,
                'nickname' => $this->player->nickname,
                'image' => $this->player->image ? asset('storage/'.$this->player->image) : '',
            ],
            'unresolved_name' => $this->unresolved_name,
            'team_id' => $this->team_id,
            'starter' => $this->starter,
            'position' => $this->position,
            'jersey' => $this->jersey,
            'subbed_in' => $this->subbed_in,
            'subbed_out' => $this->subbed_out,
            'sub_minute' => $this->sub_minute,
            'counterpart_player' => $this->counterpartPlayer === null ? null : [
                'id' => $this->counterpartPlayer->id,
                'nickname' => $this->counterpartPlayer->nickname,
            ],
            'points' => $this->fantasy_points,
            'stats' => $this->resolved_stats,
        ];
    }
}
