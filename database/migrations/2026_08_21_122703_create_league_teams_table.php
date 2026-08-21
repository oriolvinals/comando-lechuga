<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('league_teams', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(false)->default('');
            $table->string('logo')->nullable(false)->default('');
            $table->foreignId('league_id')->nullable(false)->constrained();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_teams');
    }
};
