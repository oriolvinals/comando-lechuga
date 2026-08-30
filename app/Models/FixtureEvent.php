<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FixtureEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fixture_id
 * @property-read int $team_id
 * @property-read int|null $player_id
 * @property-read int|null $match_data_id
 * @property-read string|null $unresolved_name
 * @property-read string $type
 * @property-read int $minute
 * @property-read bool $is_own_goal
 * @property-read bool $is_penalty
 */
#[UseFactory(FixtureEventFactory::class)]
#[Table(name: 'fixture_events', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fixture_id', 'team_id', 'player_id', 'match_data_id', 'unresolved_name', 'type', 'minute', 'is_own_goal', 'is_penalty'])]
class FixtureEvent extends Model
{
    /** @use HasFactory<FixtureEventFactory> */
    use HasFactory;

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => '',
        'minute' => 0,
        'is_own_goal' => false,
        'is_penalty' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fixture_id' => 'int',
            'team_id' => 'int',
            'player_id' => 'int',
            'match_data_id' => 'int',
            'unresolved_name' => 'string',
            'type' => 'string',
            'minute' => 'int',
            'is_own_goal' => 'bool',
            'is_penalty' => 'bool',
        ];
    }
}
