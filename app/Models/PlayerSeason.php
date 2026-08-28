<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\PlayerSeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $player_id
 * @property-read int $season_id
 * @property-read PlayerPosition $position
 * @property-read int $market_value
 * @property-read int $market_value_difference
 * @property-read int $points
 * @property-read string $average_points
 */
#[UseFactory(PlayerSeasonFactory::class)]
#[Table(name: 'player_seasons', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['player_id', 'season_id', 'position', 'market_value', 'market_value_difference', 'points', 'average_points'])]
class PlayerSeason extends Model
{
    /** @use HasFactory<PlayerSeasonFactory> */
    use HasFactory;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'market_value' => 0,
        'market_value_difference' => 0,
        'points' => 0,
        'average_points' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'player_id' => 'int',
            'season_id' => 'int',
            'position' => PlayerPosition::class,
            'market_value' => 'int',
            'market_value_difference' => 'int',
            'points' => 'int',
            'average_points' => 'decimal:2',
        ];
    }
}
