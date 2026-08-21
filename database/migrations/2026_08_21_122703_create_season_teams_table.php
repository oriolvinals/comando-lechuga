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
            $table->string('name')->nullable(false)->default('');
            $table->string('logo')->nullable(false)->default('');
            $table->foreignId('season_id')->nullable(false)->constrained();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_teams');
    }
};
