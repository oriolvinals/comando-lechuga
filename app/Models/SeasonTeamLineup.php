<?php

namespace App\Models;

use Database\Factories\SeasonTeamLineupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read int $id
 * @property-read int $season_team_id
 * @property-read array<int, int> $tactical_formation
 * @property-read int $points
 * @property-read int $week_number
 */
#[UseFactory(SeasonTeamLineupFactory::class)]
#[Table(name: 'season_team_lineups', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['season_team_id', 'tactical_formation', 'points', 'week_number'])]
class SeasonTeamLineup extends Model
{
    /** @use HasFactory<SeasonTeamLineupFactory> */
    use HasFactory;

    /** @return BelongsTo<SeasonTeam, $this> */
    public function seasonTeam(): BelongsTo
    {
        return $this->belongsTo(SeasonTeam::class);
    }

    /** @return HasMany<SeasonTeamLineupPlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(SeasonTeamLineupPlayer::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'tactical_formation' => '[]',
        'points' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_team_id' => 'int',
            'tactical_formation' => 'array',
            'points' => 'int',
            'week_number' => 'int',
        ];
    }
}
