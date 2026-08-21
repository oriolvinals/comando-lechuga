<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->integer('points')->nullable(false)->default(0);
            $table->unsignedTinyInteger('week_number')->nullable(false);
            $table->json('stats')->nullable(false);
            $table->boolean('ideal_formation')->nullable(false)->default(false);

            $table->unique(['player_id', 'week_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_scores');
    }
};
