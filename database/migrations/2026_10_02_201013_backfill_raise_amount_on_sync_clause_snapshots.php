<?php

declare(strict_types=1);

use App\Enums\ClauseSnapshotSource;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Services\ClauseSnapshotRaise;
use Illuminate\Database\Migrations\Migration;

/**
 * Sync clause rows were stored with raise_amount 0 whatever the jump: fill in
 * the raise each one shows (see ClauseSnapshotRaise), oldest first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $clauseSnapshotRaise = app(ClauseSnapshotRaise::class);

        ManagerPlayerClauseSnapshot::query()
            ->where('source', ClauseSnapshotSource::Sync)
            ->orderBy('id')
            ->each(function (ManagerPlayerClauseSnapshot $snapshot) use ($clauseSnapshotRaise): void {
                $snapshot->update([
                    'raise_amount' => $clauseSnapshotRaise->forSync(
                        $snapshot->season_manager_id,
                        $snapshot->player_id,
                        $snapshot->buyout_clause,
                        $snapshot->market_value,
                        $snapshot->captured_at,
                        $snapshot->id,
                    ),
                ]);
            });
    }

    public function down(): void
    {
        ManagerPlayerClauseSnapshot::query()
            ->where('source', ClauseSnapshotSource::Sync)
            ->update(['raise_amount' => 0]);
    }
};
