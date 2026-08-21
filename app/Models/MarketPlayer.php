<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MarketPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fantasy_id
 * @property-read CarbonImmutable $expires_at
 * @property-read int $bids
 * @property-read int $player_id
 * @property-read int $sale_price
 * @property-read int $value
 */
#[UseFactory(MarketPlayerFactory::class)]
#[Table(name: 'market_players', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'expires_at', 'bids', 'player_id', 'sale_price', 'value'])]
class MarketPlayer extends Model
{
    /** @use HasFactory<MarketPlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'bids' => 0,
        'sale_price' => 0,
        'value' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'int',
            'expires_at' => 'immutable_datetime',
            'bids' => 'int',
            'player_id' => 'int',
            'sale_price' => 'int',
            'value' => 'int',
        ];
    }
}
