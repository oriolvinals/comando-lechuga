<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manager_lineup_players', function (Blueprint $table): void {
            $table->integer('points')->nullable()->after('fixture_id');
        });
    }

    public function down(): void
    {
        Schema::table('manager_lineup_players', function (Blueprint $table): void {
            $table->dropColumn('points');
        });
    }
};
