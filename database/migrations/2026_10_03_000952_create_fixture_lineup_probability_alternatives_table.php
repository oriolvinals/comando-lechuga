<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The players FútbolFantasy lists under a probable starter as the ones who
 * could start instead (`a.juggador.pos-1`, `pos-2`…). Index and key names are
 * explicit: the generated ones pass MySQL's 64-character limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixture_lineup_probability_alternatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_lineup_probability_id')
                ->constrained(table: 'fixture_lineup_probabilities', indexName: 'flp_alternatives_probability_foreign')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->foreignId('player_id')
                ->nullable()
                ->constrained(indexName: 'flp_alternatives_player_foreign')
                ->nullOnDelete();
            $table->string('name')->default('');
            $table->string('futbolfantasy_slug')->default('');
            $table->timestamps();

            $table->unique(['fixture_lineup_probability_id', 'position'], 'flp_alternatives_probability_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_lineup_probability_alternatives');
    }
};
