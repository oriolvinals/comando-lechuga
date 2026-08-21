<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_markets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('fantasy_id')->nullable(false);
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->date('date')->nullable(false);
            $table->unsignedInteger('value')->nullable(false)->default(0);

            $table->unique(['player_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_markets');
    }
};
