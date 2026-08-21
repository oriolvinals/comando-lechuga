<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('players', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('fantasy_id')->nullable(false);
            $table->string('position')->nullable(false);
            $table->string('nickname')->nullable(false)->default('');
            $table->string('status')->nullable(false);
            $table->unsignedInteger('market_value')->nullable(false)->default(0);
            $table->unsignedInteger('points')->nullable(false)->default(0);
            $table->decimal('average_points')->nullable(false)->default(0);
            $table->string('image')->nullable(false)->default('');
            $table->foreignId('team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('players');
    }
};
