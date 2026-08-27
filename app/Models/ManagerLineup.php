<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ManagerLineupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read int $id
 * @property-read int $season_manager_id
 * @property-read array<int, int> $tactical_formation
 * @property-read int $points
 * @property-read int $week_number
 */
#[UseFactory(ManagerLineupFactory::class)]
#[Table(name: 'manager_lineups', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['season_manager_id', 'tactical_formation', 'points', 'week_number'])]
class ManagerLineup extends Model
{
    /** @use HasFactory<ManagerLineupFactory> */
    use HasFactory;

    /** @return BelongsTo<SeasonManager, $this> */
    public function seasonManager(): BelongsTo
    {
        return $this->belongsTo(SeasonManager::class);
    }

    /** @return HasMany<ManagerLineupPlayer, $this> */
    public function players(): HasMany
    {
        return $this->hasMany(ManagerLineupPlayer::class);
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
            'season_manager_id' => 'int',
            'tactical_formation' => 'array',
            'points' => 'int',
            'week_number' => 'int',
        ];
    }
}
