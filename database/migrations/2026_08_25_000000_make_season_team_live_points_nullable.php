<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_teams', function (Blueprint $table): void {
            $table->unsignedInteger('live_points')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('season_teams', function (Blueprint $table): void {
            $table->unsignedInteger('live_points')->nullable(false)->default(0)->change();
        });
    }
};
