<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_team_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->json('tactical_formation')->nullable(false);
            $table->integer('points')->nullable(false)->default(0);
            $table->unsignedTinyInteger('week_number')->nullable(false);
            $table->unique(['season_team_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_team_lineups');
    }
};
