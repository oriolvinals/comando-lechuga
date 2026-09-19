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
            $table->decimal('local_possession', 4, 1)->nullable()->after('referee');
            $table->decimal('guest_possession', 4, 1)->nullable()->after('local_possession');
            $table->unsignedSmallInteger('local_corners')->nullable()->after('guest_possession');
            $table->unsignedSmallInteger('guest_corners')->nullable()->after('local_corners');
            $table->unsignedSmallInteger('local_key_passes')->nullable()->after('guest_corners');
            $table->unsignedSmallInteger('guest_key_passes')->nullable()->after('local_key_passes');
        });
    }

    public function down(): void
    {
        Schema::table('fixtures', function (Blueprint $table): void {
            $table->dropColumn([
                'local_possession',
                'guest_possession',
                'local_corners',
                'guest_corners',
                'local_key_passes',
                'guest_key_passes',
            ]);
        });
    }
};
