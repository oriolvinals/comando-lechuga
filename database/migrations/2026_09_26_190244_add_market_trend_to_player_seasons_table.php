<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_seasons', function (Blueprint $table): void {
            $table->string('market_trend')->nullable()->after('market_value_difference');
        });
    }

    public function down(): void
    {
        Schema::table('player_seasons', function (Blueprint $table): void {
            $table->dropColumn('market_trend');
        });
    }
};
