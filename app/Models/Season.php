<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property-read string $name
 * @property-read bool $current
 */
#[UseFactory(SeasonFactory::class)]
#[Table(name: 'seasons', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'name', 'current'])]
class Season extends Model
{
    /** @use HasFactory<SeasonFactory> */
    use HasFactory;

    /** @return BelongsToMany<Team, $this> */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'fantasy_id' => '',
        'name' => '',
        'current' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'string',
            'name' => 'string',
            'current' => 'bool',
        ];
    }
}
