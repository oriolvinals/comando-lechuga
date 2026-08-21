<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_activities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('fantasy_id')->nullable(false);
            $table->string('type')->nullable(false);
            $table->foreignId('season_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('source_season_team_id')->nullable(false)->constrained('season_teams')->cascadeOnDelete();
            $table->foreignId('target_season_team_id')->nullable()->constrained('season_teams')->nullOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->unsignedInteger('amount')->nullable();
            $table->unsignedTinyInteger('week_number')->nullable();
            $table->timestamp('occurred_at')->nullable(false);

            $table->unique(['season_id', 'fantasy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_activities');
    }
};
