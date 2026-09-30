<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlayerStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PlayerDailySignalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's signals on one day (the last run of that day wins): status,
 * FútbolFantasy's start probability for his next match, that match's 0–10
 * difficulty and whether he is listed in the league market. Kept to measure
 * later whether they move tomorrow's value (value forecast spec §2). God
 * mode only: never exposed by the public API.
 *
 * @property-read int $id
 * @property-read int $season_id
 * @property-read int $player_id
 * @property-read CarbonImmutable $date
 * @property-read PlayerStatus $status
 * @property-read int|null $next_fixture_id
 * @property-read int|null $start_probability 0–100
 * @property-read bool $predicted_starter
 * @property-read bool|null $confirmed_starter
 * @property-read float|null $next_difficulty 0–10, 10 = hardest
 * @property-read bool $listed
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(PlayerDailySignalFactory::class)]
#[Table(name: 'player_daily_signals', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['season_id', 'player_id', 'date', 'status', 'next_fixture_id', 'start_probability', 'predicted_starter', 'confirmed_starter', 'next_difficulty', 'listed'])]
class PlayerDailySignal extends Model
{
    /** @use HasFactory<PlayerDailySignalFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'predicted_starter' => false,
        'listed' => false,
    ];

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
            'season_id' => 'int',
            'player_id' => 'int',
            'date' => 'immutable_date',
            'status' => PlayerStatus::class,
            'next_fixture_id' => 'int',
            'start_probability' => 'int',
            'predicted_starter' => 'bool',
            'confirmed_starter' => 'bool',
            'next_difficulty' => 'float',
            'listed' => 'bool',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
