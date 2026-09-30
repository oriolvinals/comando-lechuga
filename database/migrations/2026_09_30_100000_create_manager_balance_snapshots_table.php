<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_manager_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('money');
            $table->timestamp('captured_at');

            $table->unique(['season_manager_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_balance_snapshots');
    }
};
