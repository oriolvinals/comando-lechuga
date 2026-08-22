<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerPosition;
use Database\Factories\SeasonTeamLineupPlayerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $season_team_lineup_id
 * @property-read int $player_id
 * @property-read int|null $points
 * @property-read PlayerPosition $position
 */
#[UseFactory(SeasonTeamLineupPlayerFactory::class)]
#[Table(name: 'season_team_lineup_players', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['season_team_lineup_id', 'player_id', 'points', 'position'])]
class SeasonTeamLineupPlayer extends Model
{
    /** @use HasFactory<SeasonTeamLineupPlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<SeasonTeamLineup, $this> */
    public function lineup(): BelongsTo
    {
        return $this->belongsTo(SeasonTeamLineup::class, 'season_team_lineup_id');
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
            'season_team_lineup_id' => 'int',
            'player_id' => 'int',
            'points' => 'int',
            'position' => PlayerPosition::class,
        ];
    }
}
