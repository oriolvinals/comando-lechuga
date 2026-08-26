<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_team_players', function (Blueprint $table): void {
            $table->timestamp('shielded_until')->nullable()->after('shielded');
        });
    }

    public function down(): void
    {
        Schema::table('season_team_players', function (Blueprint $table): void {
            $table->dropColumn('shielded_until');
        });
    }
};
