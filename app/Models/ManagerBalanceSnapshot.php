<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ManagerBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reading of a manager's real cash (`teamMoney`). LaLiga Fantasy only sends
 * it for the connected account. PRIVATE: never exposed through /api.
 *
 * @property-read int $id
 * @property-read int $season_manager_id
 * @property-read int $money
 * @property-read CarbonImmutable $captured_at
 */
#[UseFactory(ManagerBalanceSnapshotFactory::class)]
#[Table(name: 'manager_balance_snapshots', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['season_manager_id', 'money', 'captured_at'])]
class ManagerBalanceSnapshot extends Model
{
    /** @use HasFactory<ManagerBalanceSnapshotFactory> */
    use HasFactory;

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
            'money' => 'int',
            'captured_at' => 'immutable_datetime',
        ];
    }
}
