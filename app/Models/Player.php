<?php

namespace App\Models;

use App\Enums\PlayerPosition;
use App\Enums\PlayerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fantasy_id
 * @property-read PlayerPosition $position
 * @property-read string $nickname
 * @property-read PlayerStatus $status
 * @property-read int $market_value
 * @property-read int $points
 * @property-read string $average_points
 * @property-read string $image
 * @property-read int $team_id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(PlayerFactory::class)]
#[Table(name: 'players', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['fantasy_id', 'position', 'nickname', 'status', 'market_value', 'points', 'average_points', 'image', 'team_id'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'nickname' => '',
        'market_value' => 0,
        'points' => 0,
        'average_points' => 0,
        'image' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'int',
            'position' => PlayerPosition::class,
            'nickname' => 'string',
            'status' => PlayerStatus::class,
            'market_value' => 'int',
            'points' => 'int',
            'average_points' => 'decimal:2',
            'image' => 'string',
            'team_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
