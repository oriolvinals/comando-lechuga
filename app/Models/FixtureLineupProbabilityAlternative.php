<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FixtureLineupProbabilityAlternativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A player FútbolFantasy lists under a probable starter as one who could
 * start instead (`a.juggador.pos-N`, N ≥ 1), whatever the starter's %.
 * Replaced on every predicted sync and deleted once FF confirms the lineup.
 *
 * @property-read int $id
 * @property-read int $fixture_lineup_probability_id
 * @property-read int $position FF's order under the starter: 1 for `pos-1`, 2 for `pos-2`…
 * @property-read int|null $player_id Our player, null when he couldn't be linked
 * @property-read string $name FF's short name (`.truncate-name`)
 * @property-read string $futbolfantasy_slug FF's player slug (`/jugadores/{slug}/…`)
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(FixtureLineupProbabilityAlternativeFactory::class)]
#[Table(name: 'fixture_lineup_probability_alternatives', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['fixture_lineup_probability_id', 'position', 'player_id', 'name', 'futbolfantasy_slug'])]
class FixtureLineupProbabilityAlternative extends Model
{
    /** @use HasFactory<FixtureLineupProbabilityAlternativeFactory> */
    use HasFactory;

    /** @return BelongsTo<FixtureLineupProbability, $this> */
    public function probability(): BelongsTo
    {
        return $this->belongsTo(FixtureLineupProbability::class, 'fixture_lineup_probability_id');
    }

    /** @return BelongsTo<Player, $this> */
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'fixture_lineup_probability_id' => 'int',
            'position' => 'int',
            'player_id' => 'int',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
