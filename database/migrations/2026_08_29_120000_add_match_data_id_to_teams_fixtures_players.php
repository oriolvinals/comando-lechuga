<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->unsignedInteger('match_data_id')->nullable()->unique()->after('fantasy_id');
        });

        Schema::table('fixtures', function (Blueprint $table): void {
            $table->unsignedInteger('match_data_id')->nullable()->unique()->after('fantasy_id');
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->unsignedInteger('match_data_id')->nullable()->unique()->after('fantasy_id');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('match_data_id');
        });

        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn('match_data_id');
        });

        Schema::table('players', function (Blueprint $table): void {
            $table->dropColumn('match_data_id');
        });
    }
};
