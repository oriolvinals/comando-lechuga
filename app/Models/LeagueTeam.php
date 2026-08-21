<?php

namespace App\Models;

use Database\Factories\LeagueTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(name: 'league_teams', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['name', 'logo', 'league_id'])]
class LeagueTeam extends Model
{
    /** @use HasFactory<LeagueTeamFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'name' => '',
        'logo' => '',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'name' => 'string',
            'logo' => 'string',
            'league_id' => 'int',
        ];
    }
}
