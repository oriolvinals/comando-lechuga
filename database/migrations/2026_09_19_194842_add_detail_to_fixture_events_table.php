<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixture_events', function (Blueprint $table): void {
            $table->string('detail')->default('')->after('unresolved_name');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_events', function (Blueprint $table): void {
            $table->dropColumn('detail');
        });
    }
};
