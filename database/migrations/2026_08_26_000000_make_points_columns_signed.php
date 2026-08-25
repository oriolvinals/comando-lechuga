<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_teams', function (Blueprint $table): void {
            $table->integer('total_points')->nullable(false)->default(0)->change();
            $table->integer('live_points')->nullable()->default(null)->change();
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->integer('points')->nullable(false)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('season_teams', function (Blueprint $table): void {
            $table->unsignedInteger('total_points')->nullable(false)->default(0)->change();
            $table->unsignedInteger('live_points')->nullable()->default(null)->change();
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedInteger('points')->nullable(false)->default(0)->change();
        });
    }
};
