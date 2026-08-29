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
        Schema::table('seasons', function (Blueprint $table): void {
            $table->string('match_data_season_slug')->nullable()->after('fantasy_id');
        });

        DB::table('seasons')->update(['match_data_season_slug' => '2026-27-laliga']);
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table): void {
            $table->dropColumn('match_data_season_slug');
        });
    }
};
