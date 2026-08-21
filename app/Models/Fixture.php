<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FixtureState;
use Carbon\CarbonImmutable;
use Database\Factories\FixtureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fantasy_id
 * @property-read int $season_id
 * @property-read int $week_number
 * @property-read CarbonImmutable $date
 * @property-read int $team_local_id
 * @property-read int $team_guest_id
 * @property-read int|null $local_score
 * @property-read int|null $guest_score
 * @property-read FixtureState $state
 */
#[UseFactory(FixtureFactory::class)]
#[Table(name: 'fixtures', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fantasy_id', 'season_id', 'week_number', 'date', 'team_local_id', 'team_guest_id', 'local_score', 'guest_score', 'state'])]
class Fixture extends Model
{
    /** @use HasFactory<FixtureFactory> */
    use HasFactory;

    /** @return BelongsTo<Season, $this> */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function localTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_local_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function guestTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_guest_id');
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'state' => FixtureState::Scheduled,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fantasy_id' => 'int',
            'season_id' => 'int',
            'week_number' => 'int',
            'date' => 'immutable_datetime',
            'team_local_id' => 'int',
            'team_guest_id' => 'int',
            'local_score' => 'int',
            'guest_score' => 'int',
            'state' => FixtureState::class,
        ];
    }
}
