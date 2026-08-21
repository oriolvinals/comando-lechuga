<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table): void {
            $table->id();
            $table->string('fantasy_id')->nullable(false)->default('');
            $table->string('name')->nullable(false)->default('');
            $table->date('start_date')->nullable(false);
            $table->date('end_date')->nullable(false);
            $table->unsignedSmallInteger('total_fixtures')->nullable(false)->default(0);
        });

        if (app()->environment('production')) {
            DB::table('seasons')->insert([
                'fantasy_id' => '017834818',
                'name' => '2026/27',
                'start_date' => '2026-06-29',
                'end_date' => '2027-08-10',
                'total_fixtures' => 38,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
