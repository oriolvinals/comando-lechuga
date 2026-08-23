<?php

declare(strict_types=1);

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
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property-read int $id
 * @property-read int $fantasy_id
 * @property-read PlayerPosition $position
 * @property-read string $nickname
 * @property-read PlayerStatus $status
 * @property-read int $market_value
 * @property-read int $market_value_difference
 * @property-read int $points
 * @property-read string $average_points
 * @property-read string $image
 * @property-read int $team_id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property array{id: int, name: string, logo: string}|null $owner_team Computed at query time by PlayersController; not a database column.
 */
#[UseFactory(PlayerFactory::class)]
#[Table(name: 'players', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['fantasy_id', 'position', 'nickname', 'status', 'market_value', 'market_value_difference', 'points', 'average_points', 'image', 'team_id'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<PlayerMarket, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(PlayerMarket::class);
    }

    /** @return HasMany<PlayerScore, $this> */
    public function scores(): HasMany
    {
        return $this->hasMany(PlayerScore::class);
    }

    /** @return HasMany<SeasonTeamLineupPlayer, $this> */
    public function lineupPlayers(): HasMany
    {
        return $this->hasMany(SeasonTeamLineupPlayer::class);
    }

    /** @return HasMany<SeasonTeamPlayer, $this> */
    public function seasonTeamPlayers(): HasMany
    {
        return $this->hasMany(SeasonTeamPlayer::class);
    }

    /** @return HasOne<MarketPlayer, $this> */
    public function marketPlayer(): HasOne
    {
        return $this->hasOne(MarketPlayer::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        $data['image'] = $this->image ? asset('storage/'.$this->image) : '';

        return $data;
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'nickname' => '',
        'market_value' => 0,
        'market_value_difference' => 0,
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
            'market_value_difference' => 'int',
            'points' => 'int',
            'average_points' => 'decimal:2',
            'image' => 'string',
            'team_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
