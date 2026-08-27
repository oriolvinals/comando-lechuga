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
        Schema::rename('season_teams', 'season_managers');
        Schema::rename('season_team_players', 'season_manager_players');
        Schema::rename('season_team_lineups', 'season_manager_lineups');
        Schema::rename('season_team_lineup_players', 'season_manager_lineup_players');

        Schema::table('season_manager_players', function (Blueprint $table): void {
            $table->renameColumn('season_team_id', 'season_manager_id');
        });

        Schema::table('season_manager_lineups', function (Blueprint $table): void {
            $table->renameColumn('season_team_id', 'season_manager_id');
        });

        Schema::table('season_manager_lineup_players', function (Blueprint $table): void {
            $table->renameColumn('season_team_lineup_id', 'season_manager_lineup_id');
        });

        Schema::table('season_activities', function (Blueprint $table): void {
            $table->renameColumn('source_season_team_id', 'source_season_manager_id');
            $table->renameColumn('target_season_team_id', 'target_season_manager_id');
        });

        $this->renameLogoColumnValues();
    }

    public function down(): void
    {
        Schema::table('season_activities', function (Blueprint $table): void {
            $table->renameColumn('source_season_manager_id', 'source_season_team_id');
            $table->renameColumn('target_season_manager_id', 'target_season_team_id');
        });

        Schema::table('season_manager_lineup_players', function (Blueprint $table): void {
            $table->renameColumn('season_manager_lineup_id', 'season_team_lineup_id');
        });

        Schema::table('season_manager_lineups', function (Blueprint $table): void {
            $table->renameColumn('season_manager_id', 'season_team_id');
        });

        Schema::table('season_manager_players', function (Blueprint $table): void {
            $table->renameColumn('season_manager_id', 'season_team_id');
        });

        Schema::rename('season_manager_lineup_players', 'season_team_lineup_players');
        Schema::rename('season_manager_lineups', 'season_team_lineups');
        Schema::rename('season_manager_players', 'season_team_players');
        Schema::rename('season_managers', 'season_teams');
    }

    /**
     * The crest files themselves live directly in public/images/ and are moved
     * separately (not by this migration) — this only fixes up the path already
     * stored in the logo column so it points at the new location.
     */
    private function renameLogoColumnValues(): void
    {
        DB::table('season_managers')
            ->where('logo', 'like', 'images/teams/%')
            ->update(['logo' => DB::raw("replace(logo, 'images/teams/', 'images/managers/')")]);
    }
};
