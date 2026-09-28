<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MarketTrend;
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
 * @property-read int|null $wc26_id
 * @property-read int|null $futbolfantasy_id FútbolFantasy's player id (`jugador_{id}`), stored by season:sync-start-probabilities after the first successful link.
 * @property-read string $nickname
 * @property-read PlayerStatus $status
 * @property-read string $image
 * @property-read int $team_id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 * @property PlayerPosition|null $position Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $market_value Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $market_value_difference Computed at query time from the current season's PlayerSeason; not a database column.
 * @property MarketTrend|null $market_trend Computed at query time from the current season's PlayerSeason; not a database column.
 * @property int $points Computed at query time from the current season's PlayerSeason; not a database column.
 * @property string $average_points Computed at query time from the current season's PlayerSeason; not a database column.
 * @property array{id: int, name: string, logo: string}|null $owner_manager Computed at query time by PlayersController; not a database column.
 * @property array<int, int|null> $recent_scores Points for the last 3 played matches, oldest first, ordered by fixture date; null-padded at the end when fewer than 3 exist. Computed at query time by PlayersController; not a database column.
 * @property array<int, bool> $recent_scores_finished Per recent_scores slot, whether a real finished fixture exists there — false means the team hasn't played that many matches yet, never "not called up" (a finished fixture with no score is still true, with a null recent_scores value). Computed at query time alongside recent_scores; not a database column.
 * @property array<int, Team|null> $recent_scores_opponents Per recent_scores slot, the rival the player's team faced in that match. Computed at query time alongside recent_scores; not a database column.
 * @property array<int, bool|null>|null $recent_scores_used Per recent_scores slot, whether the player was in that manager's lineup that week. Only set on the manager ficha (SeasonManagersController); null-padded like recent_scores, and entirely absent elsewhere.
 * @property array<int, array{week_number: int, opponent: Team, is_home: bool, rival_position: int, difficulty: float}|null> $next_fixtures The team's next 3 upcoming (not yet started) fixtures, soonest first, each with the rival's current standings position and difficulty (−1 leader … +1 last); null-padded at the end when fewer than 3 remain on the calendar. Computed at query time by PlayersController; not a database column.
 * @property array<int, array{week_number: int, opponent: array<string, mixed>, points: int|null}> $api_recent_scores The team's last (up to) 3 finished matches, oldest first — unlike recent_scores, no padding: fewer entries when fewer matches have been played. `opponent` is a resolved TeamResource. Computed at query time by Api\PlayersController; not a database column.
 * @property array<int, array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, rival_position: int|null, difficulty: float|null}> $api_next_fixtures The team's next (up to) 3 scheduled matches, soonest first, each with the rival's real-table position and difficulty (−1 leader … +1 last; null when the rival isn't in the table). Unlike next_fixtures, no padding. `opponent` is a resolved TeamResource. Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{fixture_id: int, week_number: int, date: string, opponent: array<string, mixed>, is_home: bool, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, source: string, is_stale: bool, fetched_at: string|null, source_url: string}|null $api_next_start Start probability (or confirmed lineup) for the team's next match, null without data. Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{multiple: float, value: int, date: string}|null $api_value_trend_30d Current value ÷ the value ~30 days earlier (PlayerMarketMetrics::valueTrend). Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{value: float, rank: int|null, ranked: int}|null $api_points_per_million Season points per million of value, with its league rank (PlayerMarketMetrics::pointsPerMillion). Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{amount: int, paid: int, type: string, occurred_at: string}|null $api_owner_gain Current value minus what the owner paid in his latest signing/buyout (PlayerMarketMetrics::capitalGain). Computed at query time by ApiPlayerShapes; not a database column.
 * @property array{sale_price: int, market_value: int, bids: int, expires_at: string, seller: string}|null $api_market_listing The player's current market listing, if any. Computed at query time by Api\PlayersController; not a database column.
 * @property array<int, array{date: string, value: int}> $api_market_history The player's market value over time, oldest first. Computed at query time by Api\PlayersController; not a database column.
 * @property array<int, array<string, mixed>> $api_scores One entry per fixture with a FixtureLineup for this player this season, oldest first: points, the `[value, points]` stats breakdown, minutes, DAZN points (`marca_points`), starter/sub facts and who fielded him. Computed at query time by Api\PlayersController; not a database column.
 * @property array<int, array<string, mixed>> $api_ownership_activity Signing/sale/buyout activity for this player, oldest first. Resolved ActivityResource entries. Computed at query time by Api\PlayersController; not a database column.
 * @property array{fixture_id: int, week_number: int, probability: int|null, predicted_starter: bool, confirmed_starter: bool|null, confirmed_source: 'worldcup26'|'futbolfantasy'|null, is_stale: bool, fetched_at: string|null, source_url: string, team_short_name: string, opponent: Team, is_home: bool, date: string}|null $next_start Start probability (or confirmed lineup) for the player's team's next fixture. Set on the manager ficha (SeasonManagersController) and the player ficha (PlayersController); not a database column.
 */
#[UseFactory(PlayerFactory::class)]
#[Table(name: 'players', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['fantasy_id', 'wc26_id', 'futbolfantasy_id', 'nickname', 'status', 'image', 'team_id'])]
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

    /** @return HasMany<FixtureLineupProbability, $this> */
    public function lineupProbabilities(): HasMany
    {
        return $this->hasMany(FixtureLineupProbability::class);
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
            'wc26_id' => 'int',
            'futbolfantasy_id' => 'int',
            'nickname' => 'string',
            'status' => PlayerStatus::class,
            'image' => 'string',
            'team_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
