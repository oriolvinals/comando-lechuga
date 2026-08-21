<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_players', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fantasy_id')->nullable(false)->unique();
            $table->timestamp('expires_at')->nullable(false);
            $table->unsignedInteger('bids')->nullable(false)->default(0);
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sale_price')->nullable(false)->default(0);
            $table->unsignedInteger('value')->nullable(false)->default(0);
            $table->unique('player_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_players');
    }
};
