<?php

use App\Enums\FixtureState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixtures', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('fantasy_id')->nullable(false);
            $table->foreignId('season_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('week_number')->nullable(false);
            $table->timestamp('date')->nullable(false);
            $table->foreignId('team_local_id')->nullable(false)->constrained('teams')->cascadeOnDelete();
            $table->foreignId('team_guest_id')->nullable(false)->constrained('teams')->cascadeOnDelete();
            $table->unsignedTinyInteger('local_score')->nullable();
            $table->unsignedTinyInteger('guest_score')->nullable();
            $table->string('state')->nullable(false)->default(FixtureState::Scheduled->value);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixtures');
    }
};
