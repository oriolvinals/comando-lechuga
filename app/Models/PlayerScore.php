<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlayerScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $player_id
 * @property-read int $points
 * @property-read int $week_number
 * @property-read array<string, list<int>> $stats
 * @property-read bool $ideal_formation
 */
#[UseFactory(PlayerScoreFactory::class)]
#[Table(name: 'player_scores', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['player_id', 'points', 'week_number', 'stats', 'ideal_formation'])]
class PlayerScore extends Model
{
    /** @use HasFactory<PlayerScoreFactory> */
    use HasFactory;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'points' => 0,
        'stats' => '[]',
        'ideal_formation' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'player_id' => 'int',
            'points' => 'int',
            'week_number' => 'int',
            'stats' => 'array',
            'ideal_formation' => 'bool',
        ];
    }
}
