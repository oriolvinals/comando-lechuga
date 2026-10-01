<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('value_forecast_fits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->date('reference_date');
            $table->string('inputs_hash')->default('');
            $table->json('coefficients');
            $table->json('quantiles');
            $table->json('metrics');
            $table->timestamps();

            $table->unique(['season_id', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('value_forecast_fits');
    }
};
