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
            $table->string('venue')->default('')->after('guest_alternate_color');
            $table->string('venue_city')->default('')->after('venue');
            $table->unsignedInteger('attendance')->nullable()->after('venue_city');
            $table->string('referee')->default('')->after('attendance');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn(['venue', 'venue_city', 'attendance', 'referee']);
        });
    }
};
