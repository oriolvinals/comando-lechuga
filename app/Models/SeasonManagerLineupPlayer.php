<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\SeasonManagerLineupPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $season_manager_lineup_id
 * @property-read int $player_id
 * @property-read int|null $points
 * @property-read array<string, list<int>>|null $stats
 * @property-read PlayerPosition $position
 */
#[UseFactory(SeasonManagerLineupPlayerFactory::class)]
#[Table(name: 'season_manager_lineup_players', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['season_manager_lineup_id', 'player_id', 'points', 'stats', 'position'])]
class SeasonManagerLineupPlayer extends Model
{
    /** @use HasFactory<SeasonManagerLineupPlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<SeasonManagerLineup, $this> */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(SeasonManagerLineup::class, 'season_manager_lineup_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_manager_lineup_id' => 'int',
            'player_id' => 'int',
            'points' => 'int',
            'stats' => 'array',
            'position' => PlayerPosition::class,
        ];
    }
}
