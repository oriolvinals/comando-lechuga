<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FixtureLineupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $fixture_id
 * @property-read int|null $player_id
 * @property-read int $team_id
 * @property-read bool $starter
 * @property-read string $position
 * @property-read string $jersey
 * @property-read bool $subbed_in
 * @property-read bool $subbed_out
 * @property-read int|null $counterpart_player_id
 * @property-read int|null $sub_minute
 * @property-read array<int, array<string, mixed>> $stats
 * @property-read int|null $fantasy_points
 * @property-read array<string, mixed>|null $fantasy_stats
 */
#[UseFactory(FixtureLineupFactory::class)]
#[Table(name: 'fixture_lineups', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['fixture_id', 'player_id', 'team_id', 'starter', 'position', 'jersey', 'subbed_in', 'subbed_out', 'counterpart_player_id', 'sub_minute', 'stats', 'fantasy_points', 'fantasy_stats'])]
class FixtureLineup extends Model
{
    /** @use HasFactory<FixtureLineupFactory> */
    use HasFactory;

    /** @return BelongsTo<Fixture, $this> */
    public function fixture(): BelongsTo
    {
        return $this->belongsTo(Fixture::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Player, $this> */
    public function counterpartPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'counterpart_player_id');
    }

    /** @var array<string, mixed> */
    protected $attributes = [
        'starter' => false,
        'position' => '',
        'jersey' => '',
        'subbed_in' => false,
        'subbed_out' => false,
        'stats' => '[]',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fixture_id' => 'int',
            'player_id' => 'int',
            'team_id' => 'int',
            'starter' => 'bool',
            'position' => 'string',
            'jersey' => 'string',
            'subbed_in' => 'bool',
            'subbed_out' => 'bool',
            'counterpart_player_id' => 'int',
            'sub_minute' => 'int',
            'stats' => 'array',
            'fantasy_points' => 'int',
            'fantasy_stats' => 'array',
        ];
    }
}
