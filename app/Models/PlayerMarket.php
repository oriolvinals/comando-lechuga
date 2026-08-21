<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlayerMarketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fantasy_id
 * @property-read int $player_id
 * @property-read CarbonImmutable $date
 * @property-read int $value
 */
#[UseFactory(PlayerMarketFactory::class)]
#[Table(name: 'player_markets', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'player_id', 'date', 'value'])]
class PlayerMarket extends Model
{
    /** @use HasFactory<PlayerMarketFactory> */
    use HasFactory;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'value' => 0,
    ];

    protected $dateFormat = 'Y-m-d';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'int',
            'player_id' => 'int',
            'date' => 'immutable_date',
            'value' => 'int',
        ];
    }
}
