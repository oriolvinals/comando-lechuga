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
            $table->unsignedTinyInteger('dazn_estimate')->nullable()->after('fantasy_stats');
            $table->string('dazn_estimate_version')->default('')->after('dazn_estimate');
            $table->json('dazn_estimate_meta')->nullable()->after('dazn_estimate_version');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->dropColumn(['dazn_estimate', 'dazn_estimate_version', 'dazn_estimate_meta']);
        });
    }
};
