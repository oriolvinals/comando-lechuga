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
 * @property-read int|null $fantasy_id
 * @property-read int|null $match_data_id
 * @property-read string $nickname
 * @property-read PlayerStatus $status
 * @property-read string $image
 * @property-read int $team_id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property PlayerPosition|null $position Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $market_value Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $market_value_difference Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $points Computed at query time from the current season's PlayerSeason; not a database column.
 * @property string $average_points Computed at query time from the current season's PlayerSeason; not a database column.
 * @property array{id: int, name: string, logo: string}|null $owner_manager Computed at query time by PlayersController; not a database column.
 * @property array<int, int|null> $recent_scores Points for the last 3 played matches, oldest first, ordered by fixture date; null-padded at the end when fewer than 3 exist. Computed at query time by PlayersController; not a database column.
 * @property array<int, bool> $recent_scores_finished Per recent_scores slot, whether a real finished fixture exists there — false means the team hasn't played that many matches yet, never "not called up" (a finished fixture with no score is still true, with a null recent_scores value). Computed at query time alongside recent_scores; not a database column.
 * @property array<int, Team|null> $recent_scores_opponents Per recent_scores slot, the rival the player's team faced in that match. Computed at query time alongside recent_scores; not a database column.
 * @property array<int, bool|null>|null $recent_scores_used Per recent_scores slot, whether the player was in that manager's lineup that week. Only set on the manager ficha (SeasonManagersController); null-padded like recent_scores, and entirely absent elsewhere.
 * @property array<int, array{week_number: int, opponent: Team, is_home: bool}|null> $next_fixtures The team's next 3 upcoming (not yet started) fixtures, soonest first; null-padded at the end when fewer than 3 remain on the calendar. Computed at query time by PlayersController; not a database column.
 * @property array<int, array{week_number: int, opponent: array<string, mixed>, points: int|null}> $api_recent_scores The team's last (up to) 3 finished matches, oldest first — unlike recent_scores, no padding: fewer entries when fewer matches have been played. `opponent` is a resolved TeamResource. Computed at query time by Api\PlayersController; not a database column.
 * @property array<int, array{week_number: int, opponent: array<string, mixed>, is_home: bool}> $api_next_fixtures The team's next (up to) 3 scheduled matches, soonest first — unlike next_fixtures, no padding. `opponent` is a resolved TeamResource. Computed at query time by Api\PlayersController; not a database column.
 */
#[UseFactory(PlayerFactory::class)]
#[Table(name: 'players', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['fantasy_id', 'match_data_id', 'nickname', 'status', 'image', 'team_id'])]
class Player extends Model
{
    /** @use HasFactory<PlayerFactory> */
    use HasFactory;

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<PlayerSeason, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(PlayerSeason::class);
    }

    /** @return HasMany<PlayerMarket, $this> */
    public function markets(): HasMany
    {
        return $this->hasMany(PlayerMarket::class);
    }

    /** @return HasMany<ManagerLineupPlayer, $this> */
    public function lineupPlayers(): HasMany
    {
        return $this->hasMany(ManagerLineupPlayer::class);
    }

    /** @return HasMany<FixtureLineup, $this> */
    public function fixtureLineups(): HasMany
    {
        return $this->hasMany(FixtureLineup::class);
    }

    /** @return HasMany<ManagerPlayer, $this> */
    public function seasonManagerPlayers(): HasMany
    {
        return $this->hasMany(ManagerPlayer::class);
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
            'match_data_id' => 'int',
            'nickname' => 'string',
            'status' => PlayerStatus::class,
            'image' => 'string',
            'team_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
