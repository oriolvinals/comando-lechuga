<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_daily_signals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status');
            $table->foreignId('next_fixture_id')->nullable()->constrained('fixtures')->nullOnDelete();
            $table->unsignedTinyInteger('start_probability')->nullable();
            $table->boolean('predicted_starter')->default(false);
            $table->boolean('confirmed_starter')->nullable();
            $table->double('next_difficulty')->nullable();
            $table->boolean('listed')->default(false);
            $table->timestamps();

            $table->unique(['player_id', 'date']);
            $table->index(['season_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_daily_signals');
    }
};
