<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\ManagerLineupPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $manager_lineup_id
 * @property-read int $player_id
 * @property-read int|null $points
 * @property-read array<string, list<int>>|null $stats
 * @property-read PlayerPosition $position
 * @property bool $match_finished Computed at query time by SeasonManagersController; not a database column.
 */
#[UseFactory(ManagerLineupPlayerFactory::class)]
#[Table(name: 'manager_lineup_players', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['manager_lineup_id', 'player_id', 'points', 'stats', 'position'])]
class ManagerLineupPlayer extends Model
{
    /** @use HasFactory<ManagerLineupPlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<ManagerLineup, $this> */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(ManagerLineup::class, 'manager_lineup_id');
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
            'manager_lineup_id' => 'int',
            'player_id' => 'int',
            'points' => 'int',
            'stats' => 'array',
            'position' => PlayerPosition::class,
        ];
    }
}
