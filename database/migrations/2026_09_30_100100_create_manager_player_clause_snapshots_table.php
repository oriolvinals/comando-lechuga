<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_player_clause_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_manager_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('buyout_clause');
            $table->timestamp('buyout_clause_locked_until');
            $table->bigInteger('market_value');
            $table->timestamp('captured_at');
            $table->string('source')->default('sync');
            $table->bigInteger('raise_amount')->default(0);
            $table->string('note')->default('');

            $table->index(['season_manager_id', 'player_id', 'captured_at'], 'clause_snapshots_holding_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_player_clause_snapshots');
    }
};
