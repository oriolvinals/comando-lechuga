<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SeasonTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, int|null> $recent_form Points for the team's last 3 played jornadas, oldest first, ordered by week number; null-padded at the end when fewer than 3 exist. Computed at query time by HomeController; not a database column.
 */
#[Table(name: 'season_teams', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'fantasy_user_id', 'name', 'logo', 'primary_color', 'secondary_color', 'total_points', 'live_points', 'position', 'last_position', 'value', 'season_id'])]
class SeasonTeam extends Model
{
    /** @use HasFactory<SeasonTeamFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'name' => '',
        'logo' => '',
        'total_points' => 0,
        'live_points' => null,
        'position' => 1,
        'last_position' => 1,
        'value' => 0,
    ];

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return HasMany<SeasonTeamLineup, $this> */
    public function lineups(): HasMany
    {
        return $this->hasMany(SeasonTeamLineup::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        $data['logo'] = $this->logo ? asset($this->logo) : '';

        return $data;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'int',
            'fantasy_user_id' => 'int',
            'name' => 'string',
            'logo' => 'string',
            'primary_color' => 'string',
            'secondary_color' => 'string',
            'total_points' => 'int',
            'live_points' => 'int',
            'position' => 'int',
            'last_position' => 'int',
            'value' => 'int',
            'season_id' => 'int',
        ];
    }
}
