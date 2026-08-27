<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('season_manager_players', 'manager_players');
        Schema::rename('season_manager_lineups', 'manager_lineups');
        Schema::rename('season_manager_lineup_players', 'manager_lineup_players');
        Schema::rename('season_activities', 'activities');

        Schema::table('manager_lineup_players', function (Blueprint $table): void {
            $table->renameColumn('season_manager_lineup_id', 'manager_lineup_id');
        });
    }

    public function down(): void
    {
        Schema::table('manager_lineup_players', function (Blueprint $table): void {
            $table->renameColumn('manager_lineup_id', 'season_manager_lineup_id');
        });

        Schema::rename('activities', 'season_activities');
        Schema::rename('manager_lineup_players', 'season_manager_lineup_players');
        Schema::rename('manager_lineups', 'season_manager_lineups');
        Schema::rename('manager_players', 'season_manager_players');
    }
};
