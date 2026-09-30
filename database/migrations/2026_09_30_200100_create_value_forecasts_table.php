<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_forecasts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');
            $table->date('target_date');
            $table->unsignedBigInteger('value');
            $table->unsignedBigInteger('predicted_value');
            $table->unsignedBigInteger('low');
            $table->unsignedBigInteger('high');
            $table->double('change_pct');
            $table->double('up_probability');
            $table->json('reasons');
            $table->timestamps();

            $table->unique(['season_id', 'player_id', 'target_date']);
            $table->index(['player_id', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_forecasts');
    }
};
