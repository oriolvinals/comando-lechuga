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
            $table->dropColumn(['points', 'stats']);
            $table->foreignId('fixture_id')->nullable()->after('player_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('manager_lineup_players', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('fixture_id');
            $table->integer('points')->nullable(false)->default(0);
            $table->json('stats')->nullable();
        });
    }
};
