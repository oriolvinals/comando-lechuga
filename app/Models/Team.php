<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property-read int $id
 * @property-read string $main_name
 * @property-read string $name
 * @property-read string $slug
 * @property-read string $short_name
 * @property-read string $logo
 * @property-read int $fantasy_id
 * @property-read int|null $match_data_id
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(TeamFactory::class)]
#[Table(name: 'teams', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['main_name', 'name', 'slug', 'short_name', 'logo', 'fantasy_id', 'match_data_id'])]
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /** @return BelongsToMany<Season, $this> */
    public function seasons(): BelongsToMany
    {
        return $this->belongsToMany(Season::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = parent::toArray();

        $data['logo'] = $this->logo ? asset('storage/'.$this->logo) : '';

        return $data;
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'main_name' => '',
        'name' => '',
        'slug' => '',
        'short_name' => '',
        'logo' => '',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'main_name' => 'string',
            'name' => 'string',
            'slug' => 'string',
            'short_name' => 'string',
            'logo' => 'string',
            'fantasy_id' => 'int',
            'match_data_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
