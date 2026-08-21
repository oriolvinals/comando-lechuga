<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('main_name')->nullable(false)->default('');
            $table->string('name')->nullable(false)->default('');
            $table->string('slug')->nullable(false)->default('');
            $table->string('short_name')->nullable(false)->default('');
            $table->string('logo')->nullable(false)->default('');
            $table->integer('fantasy_id')->nullable(false);
            $table->timestamps();
        });

        Schema::create('league_team', function (Blueprint $table) {
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->primary(['league_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_team');
        Schema::dropIfExists('teams');
    }
};
