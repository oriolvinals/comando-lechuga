<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SeasonTeamFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table(name: 'season_teams', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'name', 'logo', 'season_id'])]
class SeasonTeam extends Model
{
    /** @use HasFactory<SeasonTeamFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'name' => '',
        'logo' => '',
    ];

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'int',
            'name' => 'string',
            'logo' => 'string',
            'season_id' => 'int',
        ];
    }
}
