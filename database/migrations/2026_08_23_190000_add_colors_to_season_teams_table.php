<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_teams', function (Blueprint $table): void {
            $table->string('primary_color')->nullable()->after('logo');
            $table->string('secondary_color')->nullable()->after('primary_color');
        });
    }

    public function down(): void
    {
        Schema::table('season_teams', function (Blueprint $table): void {
            $table->dropColumn(['primary_color', 'secondary_color']);
        });
    }
};
