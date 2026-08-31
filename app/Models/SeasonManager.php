<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SeasonManagerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property array<int, int|null> $recent_form Points for the manager's last 3 played jornadas, oldest first, ordered by week number; null-padded at the end when fewer than 3 exist. Computed at query time by HomeController; not a database column.
 * @property array<int, array{week_number: int, points: int|null, live: bool}> $api_recent_form The manager's most recent finished jornadas (oldest first), fewer than 3 entries when fewer have finished; when the current jornada is live, only its last 2 finished entries are kept and a 3rd entry for the live jornada (live: true, points from live_points) is appended. Computed at query time by Api\StandingsController; not a database column.
 * @property array<int, array<string, mixed>> $api_roster The manager's current squad. Computed at query time by Api\ManagerController; not a database column.
 * @property array<int, array<string, mixed>> $api_lineup_history The manager's lineup for every played jornada. Computed at query time by Api\ManagerController; not a database column.
 * @property array<int, array<string, mixed>> $api_recent_activity The manager's last 10 activities as source or target. Computed at query time by Api\ManagerController; not a database column.
 */
#[Table(name: 'season_managers', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'fantasy_user_id', 'name', 'logo', 'primary_color', 'secondary_color', 'total_points', 'live_points', 'position', 'last_position', 'value', 'season_id'])]
class SeasonManager extends Model
{
    /** @use HasFactory<SeasonManagerFactory> */
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

    /** @return HasMany<ManagerLineup, $this> */
    public function lineups(): HasMany
    {
        return $this->hasMany(ManagerLineup::class);
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
