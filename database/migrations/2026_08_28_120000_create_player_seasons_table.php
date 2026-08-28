<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_seasons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('season_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->string('position')->nullable(false);
            $table->unsignedInteger('market_value')->nullable(false)->default(0);
            $table->integer('market_value_difference')->nullable(false)->default(0);
            $table->integer('points')->nullable(false)->default(0);
            $table->decimal('average_points')->nullable(false)->default(0);
            $table->unique(['player_id', 'season_id'], 'player_seasons_unique');
        });

        $currentSeasonId = DB::table('seasons')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now())
            ->value('id');

        // The five columns are dropped below whatever happens, so a backfill that
        // silently does nothing is permanent, unflagged data loss. Refuse instead —
        // but only when there is actually data at stake: a players table that is
        // still empty (any fresh database, the test suite's included) has nothing
        // to lose and migrates straight through.
        if ($currentSeasonId === null) {
            if (DB::table('players')->exists()) {
                throw new RuntimeException('No current season found — refusing to drop player season columns without a season to backfill into.');
            }
        } else {
            DB::table('players')
                ->select('id', 'position', 'market_value', 'market_value_difference', 'points', 'average_points')
                ->orderBy('id')
                ->chunkById(200, function ($players) use ($currentSeasonId): void {
                    DB::table('player_seasons')->insert($players->map(fn ($player): array => [
                        'player_id' => $player->id,
                        'season_id' => $currentSeasonId,
                        'position' => $player->position,
                        'market_value' => $player->market_value,
                        'market_value_difference' => $player->market_value_difference,
                        'points' => $player->points,
                        'average_points' => $player->average_points,
                    ])->all());
                });
        }

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn(['position', 'market_value', 'market_value_difference', 'points', 'average_points']);
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table): void {
            $table->string('position')->nullable(false)->default('');
            $table->unsignedInteger('market_value')->nullable(false)->default(0);
            $table->integer('market_value_difference')->nullable(false)->default(0);
            $table->integer('points')->nullable(false)->default(0);
            $table->decimal('average_points')->nullable(false)->default(0);
        });

        foreach (DB::table('player_seasons')->get() as $row) {
            DB::table('players')->where('id', $row->player_id)->update([
                'position' => $row->position,
                'market_value' => $row->market_value,
                'market_value_difference' => $row->market_value_difference,
                'points' => $row->points,
                'average_points' => $row->average_points,
            ]);
        }

        Schema::dropIfExists('player_seasons');
    }
};
