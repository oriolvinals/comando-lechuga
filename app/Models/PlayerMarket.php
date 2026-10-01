<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlayerMarketFactory;
use Illuminate\Database\Eloquent\Attributes\DateFormat;
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
#[DateFormat('Y-m-d')]
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

    /**
     * The player's last `$count` daily values up to `$date` (Y-m-d),
     * oldest first.
     *
     * @return list<int>
     */
    public static function recentValues(int $playerId, string $date, int $count): array
    {
        return array_values(self::query()
            ->where('player_id', $playerId)
            ->where('date', '<=', $date)
            ->orderByDesc('date')
            ->limit($count)
            ->pluck('value')
            ->reverse()
            ->map(static fn (mixed $value): int => (int) $value)
            ->all());
    }
}
