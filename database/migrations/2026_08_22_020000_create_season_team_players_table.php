<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_team_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('buyout_clause')->nullable(false)->default(0);
            $table->timestamp('buyout_clause_locked_until')->nullable(false);
            $table->boolean('shielded')->nullable(false)->default(false);
            $table->unique(['season_team_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_team_players');
    }
};
