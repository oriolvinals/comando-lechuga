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
            $table->string('local_color')->nullable()->after('guest_formation');
            $table->string('local_alternate_color')->nullable()->after('local_color');
            $table->string('guest_color')->nullable()->after('local_alternate_color');
            $table->string('guest_alternate_color')->nullable()->after('guest_color');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn(['local_color', 'local_alternate_color', 'guest_color', 'guest_alternate_color']);
        });
    }
};
