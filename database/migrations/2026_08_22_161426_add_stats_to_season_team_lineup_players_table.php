<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_team_lineup_players', function (Blueprint $table): void {
            $table->json('stats')->nullable()->after('points');
        });
    }

    public function down(): void
    {
        Schema::table('season_team_lineup_players', function (Blueprint $table): void {
            $table->dropColumn('stats');
        });
    }
};
