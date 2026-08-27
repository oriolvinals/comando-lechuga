<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ManagerPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $season_manager_id
 * @property-read int $player_id
 * @property-read int $buyout_clause
 * @property-read CarbonImmutable $buyout_clause_locked_until
 * @property-read bool $shielded
 * @property-read CarbonImmutable|null $shielded_until
 */
#[UseFactory(ManagerPlayerFactory::class)]
#[Table(name: 'manager_players', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['season_manager_id', 'player_id', 'buyout_clause', 'buyout_clause_locked_until', 'shielded', 'shielded_until'])]
class ManagerPlayer extends Model
{
    /** @use HasFactory<ManagerPlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<SeasonManager, $this> */
    public function seasonManager(): BelongsTo
    {
        return $this->belongsTo(SeasonManager::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'buyout_clause' => 0,
        'shielded' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_manager_id' => 'int',
            'player_id' => 'int',
            'buyout_clause' => 'int',
            'buyout_clause_locked_until' => 'immutable_datetime',
            'shielded' => 'bool',
            'shielded_until' => 'immutable_datetime',
        ];
    }
}
