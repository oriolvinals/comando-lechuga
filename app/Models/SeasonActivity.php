<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeasonActivityType;
use Carbon\CarbonImmutable;
use Database\Factories\SeasonActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fantasy_id
 * @property-read SeasonActivityType $type
 * @property-read int $season_id
 * @property-read int $source_season_team_id
 * @property-read int|null $target_season_team_id
 * @property-read int|null $player_id
 * @property-read int|null $amount
 * @property-read int|null $week_number
 * @property-read CarbonImmutable $occurred_at
 * @property int|null $value_difference Computed at query time by ActivityController; not a database column.
 */
#[UseFactory(SeasonActivityFactory::class)]
#[Table(name: 'season_activities', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'type', 'season_id', 'source_season_team_id', 'target_season_team_id', 'player_id', 'amount', 'week_number', 'occurred_at'])]
class SeasonActivity extends Model
{
    /** @use HasFactory<SeasonActivityFactory> */
    use HasFactory;

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<SeasonTeam, $this> */
    public function sourceSeasonTeam(): BelongsTo
    {
        return $this->belongsTo(SeasonTeam::class, 'source_season_team_id');
    }

    /** @return BelongsTo<SeasonTeam, $this> */
    public function targetSeasonTeam(): BelongsTo
    {
        return $this->belongsTo(SeasonTeam::class, 'target_season_team_id');
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
            'fantasy_id' => 'int',
            'type' => SeasonActivityType::class,
            'season_id' => 'int',
            'source_season_team_id' => 'int',
            'target_season_team_id' => 'int',
            'player_id' => 'int',
            'amount' => 'int',
            'week_number' => 'int',
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
