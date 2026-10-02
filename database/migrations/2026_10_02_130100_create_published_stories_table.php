<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('published_stories', function (Blueprint $table): void {
            $table->id();
            $table->date('date');
            $table->string('type');
            $table->unsignedSmallInteger('batch');
            $table->unsignedTinyInteger('part');
            $table->unsignedTinyInteger('parts');
            $table->unsignedSmallInteger('signings_count')->default(0);
            $table->json('activity_ids');
            $table->string('media_id')->default('');
            $table->timestamp('published_at');

            $table->unique(['date', 'type', 'batch', 'part']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('published_stories');
    }
};
