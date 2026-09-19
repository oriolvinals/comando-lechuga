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
 * @property-read int|null $wc26_id
 * @property-read string|null $unresolved_name
 * @property-read string $type
 * @property-read int $minute
 * @property-read bool $is_own_goal
 * @property-read bool $is_penalty
 * @property-read string $detail The raw worldcup26 description, only kept for VAR decisions.
 */
#[UseFactory(FixtureEventFactory::class)]
#[Table(name: 'fixture_events', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fixture_id', 'team_id', 'player_id', 'wc26_id', 'unresolved_name', 'type', 'minute', 'is_own_goal', 'is_penalty', 'detail'])]
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

    /**
     * What a VAR event decided, in the app's own words — null for any other
     * event type. worldcup26 only sends a free-text description, so anything
     * we can't tell apart yet falls back to a generic label.
     */
    public function varDecisionLabel(): ?string
    {
        if ($this->type !== 'var') {
            return null;
        }

        return match (true) {
            str_contains(strtolower($this->detail), 'card upgraded') => 'Tarjeta ascendida',
            default => 'Decisión del VAR',
        };
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'type' => '',
        'minute' => 0,
        'is_own_goal' => false,
        'is_penalty' => false,
        'detail' => '',
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
            'wc26_id' => 'int',
            'unresolved_name' => 'string',
            'type' => 'string',
            'minute' => 'int',
            'is_own_goal' => 'bool',
            'is_penalty' => 'bool',
            'detail' => 'string',
        ];
    }
}
