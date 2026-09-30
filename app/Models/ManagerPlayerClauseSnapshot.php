<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClauseSnapshotSource;
use Carbon\CarbonImmutable;
use Database\Factories\ManagerPlayerClauseSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A squad player's buyout clause at one moment, stored only when the clause
 * or its lock changes. Two consecutive rows of the same holding prove a paid
 * raise: new clause − max(old clause, new market value). PRIVATE: never
 * exposed through /api.
 *
 * @property-read int $id
 * @property-read int $season_manager_id
 * @property-read int $player_id
 * @property-read int $buyout_clause
 * @property-read CarbonImmutable $buyout_clause_locked_until
 * @property-read int $market_value
 * @property-read CarbonImmutable $captured_at
 * @property-read ClauseSnapshotSource $source sync (written by the sync) or manual (entered by the user, authoritative)
 * @property-read int $raise_amount Paid raise of a manual entry (0 for sync rows); its cost is half.
 * @property-read string $note
 */
#[UseFactory(ManagerPlayerClauseSnapshotFactory::class)]
#[Table(name: 'manager_player_clause_snapshots', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['season_manager_id', 'player_id', 'buyout_clause', 'buyout_clause_locked_until', 'market_value', 'captured_at', 'source', 'raise_amount', 'note'])]
class ManagerPlayerClauseSnapshot extends Model
{
    /** @use HasFactory<ManagerPlayerClauseSnapshotFactory> */
    use HasFactory;

    /** @var array<string, mixed> */
    protected $attributes = [
        'source' => 'sync',
        'raise_amount' => 0,
        'note' => '',
    ];

    /** @return BelongsTo<SeasonManager, $this> */
    public function seasonManager(): BelongsTo
    {
        return $this->belongsTo(SeasonManager::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'int',
            'season_manager_id' => 'int',
            'player_id' => 'int',
            'buyout_clause' => 'int',
            'buyout_clause_locked_until' => 'immutable_datetime',
            'market_value' => 'int',
            'captured_at' => 'immutable_datetime',
            'source' => ClauseSnapshotSource::class,
            'raise_amount' => 'int',
            'note' => 'string',
        ];
    }
}
