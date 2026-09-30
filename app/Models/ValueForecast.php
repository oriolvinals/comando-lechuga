<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ValueForecastFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The value LaLiga Fantasy is expected to publish for a player on
 * `target_date`, forecast with what was known at the end of
 * `reference_date` (value forecast spec §1). Written by
 * season:forecast-values. God mode only: never exposed by the public API.
 *
 * @property-read int $id
 * @property-read int $season_id
 * @property-read int $player_id
 * @property-read CarbonImmutable $reference_date
 * @property-read CarbonImmutable $target_date
 * @property-read int $value The value on `reference_date`
 * @property-read int $predicted_value
 * @property-read int $low 80 % interval, low end
 * @property-read int $high 80 % interval, high end
 * @property-read float $change_pct Forecast change in percent (5.2 = +5,2 %)
 * @property-read float $up_probability 0–1
 * @property-read list<array{kind: string, label: string, impact_pct: float}> $reasons
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(ValueForecastFactory::class)]
#[Table(name: 'value_forecasts', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['season_id', 'player_id', 'reference_date', 'target_date', 'value', 'predicted_value', 'low', 'high', 'change_pct', 'up_probability', 'reasons'])]
class ValueForecast extends Model
{
    /** @use HasFactory<ValueForecastFactory> */
    use HasFactory;

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
            'reference_date' => 'immutable_date',
            'target_date' => 'immutable_date',
            'value' => 'int',
            'predicted_value' => 'int',
            'low' => 'int',
            'high' => 'int',
            'change_pct' => 'float',
            'up_probability' => 'float',
            'reasons' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
