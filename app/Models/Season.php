<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SeasonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read int $id
 * @property-read string $fantasy_id
 * @property-read string|null $match_data_season_slug
 * @property-read string $name
 * @property-read CarbonImmutable $start_date
 * @property-read CarbonImmutable $end_date
 * @property-read int $total_weeks
 * @property-read int $current_week
 */
#[UseFactory(SeasonFactory::class)]
#[Table(name: 'seasons', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'match_data_season_slug', 'name', 'start_date', 'end_date', 'total_weeks', 'current_week'])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public static function current(): self
    {
        return self::query()
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->sole();
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'fantasy_id' => '',
        'name' => '',
        'total_weeks' => 38,
        'current_week' => 1,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'string',
            'match_data_season_slug' => 'string',
            'name' => 'string',
            'start_date' => 'immutable_date',
            'end_date' => 'immutable_date',
            'total_weeks' => 'int',
            'current_week' => 'int',
        ];
    }
}
