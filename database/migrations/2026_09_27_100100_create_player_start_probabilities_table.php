<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_start_probabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixture_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('probability')->nullable();
            $table->boolean('predicted_starter')->default(false);
            $table->boolean('confirmed_starter')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['player_id', 'fixture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_start_probabilities');
    }
};
