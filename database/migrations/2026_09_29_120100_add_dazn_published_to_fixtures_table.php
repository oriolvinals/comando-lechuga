<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->boolean('dazn_published')->default(false)->after('display_clock');
        });

        $this->backfillDaznPublished();
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn('dazn_published');
        });
    }

    /**
     * Without this, every existing fixture starts with `dazn_published = false`,
     * so all past official DAZN ratings would go blank until a manual backfill
     * command runs. Marks a fixture published as soon as any of its
     * fixture_lineups rows already carries an official rating
     * (`fantasy_stats.marca_points[1] > 0`).
     *
     * Deliberately plain query builder + PHP, no app classes: migrations must
     * stay runnable as-is regardless of how the app's models evolve later.
     * Public so a test can invoke it directly (see
     * AddDaznPublishedToFixturesTableTest) without re-running up(), which
     * would fail trying to re-add a column RefreshDatabase already migrated.
     */
    public function backfillDaznPublished(): void
    {
        $fixtureIds = [];

        DB::table('fixture_lineups')
            ->whereNotNull('fantasy_stats')
            ->select('fixture_id', 'fantasy_stats')
            ->orderBy('id')
            ->chunk(500, function (Collection $rows) use (&$fixtureIds): void {
                foreach ($rows as $row) {
                    /** @var array<string, mixed>|null $stats */
                    $stats = json_decode((string) $row->fantasy_stats, true);
                    $marcaPoints = $stats['marca_points'][1] ?? null;

                    if (is_numeric($marcaPoints) && (int) $marcaPoints > 0) {
                        $fixtureIds[] = $row->fixture_id;
                    }
                }
            });

        if ($fixtureIds !== []) {
            DB::table('fixtures')->whereIn('id', array_unique($fixtureIds))->update(['dazn_published' => true]);
        }
    }
};
