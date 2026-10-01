<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ValueForecastFitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The value forecast model as fitted for one reference date: its
 * coefficients, residual quantiles and size, plus the fingerprint of the
 * inputs it was fitted on (season:forecast-values skips a run whose
 * fingerprint didn't change). God mode only.
 *
 * @property-read int $id
 * @property-read int $season_id
 * @property-read CarbonImmutable $reference_date
 * @property-read string $inputs_hash
 * @property-read list<float> $coefficients
 * @property-read array<string, mixed> $quantiles
 * @property-read array<string, mixed> $metrics
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(ValueForecastFitFactory::class)]
#[Table(name: 'value_forecast_fits', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['season_id', 'reference_date', 'inputs_hash', 'coefficients', 'quantiles', 'metrics'])]
class ValueForecastFit extends Model
{
    /** @use HasFactory<ValueForecastFitFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'inputs_hash' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_id' => 'int',
            'reference_date' => 'immutable_date',
            'coefficients' => 'array',
            'quantiles' => 'array',
            'metrics' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
