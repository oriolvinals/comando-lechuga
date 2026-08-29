<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('player_scores');
    }

    public function down(): void
    {
        Schema::create('player_scores', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->integer('points')->nullable(false)->default(0);
            $table->json('stats')->nullable(false);
            $table->boolean('ideal_formation')->nullable(false)->default(false);
            $table->unique(['player_id', 'fixture_id']);
        });
    }
};
