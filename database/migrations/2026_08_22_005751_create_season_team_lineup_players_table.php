<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_team_lineup_players', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_team_lineup_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->integer('points')->nullable(false)->default(0);
            $table->string('position')->nullable(false)->default('');
            $table->unique(['season_team_lineup_id', 'player_id'], 'season_team_lineup_players_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_team_lineup_players');
    }
};
