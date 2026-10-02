<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->text('access_token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refreshed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_access_tokens');
    }
};
