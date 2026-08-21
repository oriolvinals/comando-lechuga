<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_teams', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('fantasy_id')->nullable(false);
            $table->string('name')->nullable(false)->default('');
            $table->string('logo')->nullable(false)->default('');
            $table->unsignedInteger('total_points')->nullable(false)->default(0);
            $table->unsignedInteger('live_points')->nullable(false)->default(0);
            $table->unsignedSmallInteger('position')->nullable(false)->default(1);
            $table->unsignedSmallInteger('last_position')->nullable(false)->default(1);
            $table->foreignId('season_id')->nullable(false)->constrained();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_teams');
    }
};
