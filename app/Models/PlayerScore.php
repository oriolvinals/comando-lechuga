<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlayerScoreFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property-read int $player_id
 * @property-read int $fixture_id
 * @property-read int $team_id
 * @property-read int $points
 * @property-read array<string, list<int>> $stats
 * @property-read bool $ideal_formation
 * @property SeasonManager|null $lineup_manager Computed at query time by FixturesController/PlayersController; not a database column.
 */
#[UseFactory(PlayerScoreFactory::class)]
#[Table(name: 'player_scores', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['player_id', 'fixture_id', 'team_id', 'points', 'stats', 'ideal_formation'])]
class PlayerScore extends Model
{
    /** @use HasFactory<PlayerScoreFactory> */
    use HasFactory;

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

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

    /** @var array<string, mixed> */
    protected $attributes = [
        'points' => 0,
        'stats' => '[]',
        'ideal_formation' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'player_id' => 'int',
            'fixture_id' => 'int',
            'team_id' => 'int',
            'points' => 'int',
            'stats' => 'array',
            'ideal_formation' => 'bool',
        ];
    }
}
