<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable(false)->default('');
            $table->boolean('current')->nullable(false)->default(false);
        });

        if (app()->environment('production')) {
            DB::table('leagues')->insert([
                'name' => '2026/27',
                'current' => true,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
