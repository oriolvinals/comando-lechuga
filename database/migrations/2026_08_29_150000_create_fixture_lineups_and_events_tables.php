<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->string('local_formation')->nullable()->after('state');
            $table->string('guest_formation')->nullable()->after('local_formation');
        });

        Schema::create('fixture_lineups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->boolean('starter')->nullable(false)->default(false);
            $table->string('position')->nullable(false)->default('');
            $table->string('jersey')->nullable(false)->default('');
            $table->boolean('subbed_in')->nullable(false)->default(false);
            $table->boolean('subbed_out')->nullable(false)->default(false);
            $table->foreignId('counterpart_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->unsignedTinyInteger('sub_minute')->nullable();
            $table->json('stats')->nullable(false);

            $table->unique(['fixture_id', 'player_id']);
        });

        Schema::create('fixture_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fixture_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->nullable(false)->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('type')->nullable(false);
            $table->unsignedTinyInteger('minute')->nullable(false);
            $table->boolean('is_own_goal')->nullable(false)->default(false);
            $table->boolean('is_penalty')->nullable(false)->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixture_events');
        Schema::dropIfExists('fixture_lineups');

        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn(['local_formation', 'guest_formation']);
        });
    }
};
