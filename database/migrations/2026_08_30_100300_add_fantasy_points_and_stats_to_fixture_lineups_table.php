<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->integer('fantasy_points')->nullable()->after('stats');
            $table->json('fantasy_stats')->nullable()->after('fantasy_points');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->dropColumn(['fantasy_points', 'fantasy_stats']);
        });
    }
};
