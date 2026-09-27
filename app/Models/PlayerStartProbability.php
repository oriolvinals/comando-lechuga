<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PlayerStartProbabilityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FútbolFantasy's view of one player for one fixture: the last predicted
 * start probability, whether he was in FF's probable XI, and — once FF
 * publishes "Alineación confirmada" — whether he starts. Upserted by
 * season:sync-start-probabilities and never deleted after kickoff: the last
 * predicted % backs the "Sorpresa / Se cae · era N %" marks and is history.
 *
 * @property-read int $id
 * @property-read int $player_id
 * @property-read int $fixture_id
 * @property-read int|null $probability 0–100; null when FF gave no % (pre-season, or confirmed before we saw a %)
 * @property-read bool $predicted_starter In FF's probable XI (`data-onceFF="titular"`)
 * @property-read bool|null $confirmed_starter From FF's "Alineación confirmada": true = Titular, false = Suplente, null = not confirmed
 * @property-read CarbonImmutable $fetched_at
 * @property-read CarbonImmutable|null $created_at
 * @property-read CarbonImmutable|null $updated_at
 */
#[UseFactory(PlayerStartProbabilityFactory::class)]
#[Table(name: 'player_start_probabilities', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['player_id', 'fixture_id', 'probability', 'predicted_starter', 'confirmed_starter', 'fetched_at'])]
class PlayerStartProbability extends Model
{
    /** @use HasFactory<PlayerStartProbabilityFactory> */
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

    /** @var array<string, mixed> */
    protected $attributes = [
        'predicted_starter' => false,
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
            'probability' => 'int',
            'predicted_starter' => 'bool',
            'confirmed_starter' => 'bool',
            'fetched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
