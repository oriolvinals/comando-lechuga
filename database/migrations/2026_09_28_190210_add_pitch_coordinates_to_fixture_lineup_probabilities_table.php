<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixture_lineup_probabilities', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pitch_x')->nullable()->after('confirmed_starter');
            $table->unsignedTinyInteger('pitch_y')->nullable()->after('pitch_x');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineup_probabilities', function (Blueprint $table): void {
            $table->dropColumn(['pitch_x', 'pitch_y']);
        });
    }
};
