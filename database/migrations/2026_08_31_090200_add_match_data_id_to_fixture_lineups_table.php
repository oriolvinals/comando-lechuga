<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->unsignedBigInteger('match_data_id')->after('unresolved_name');
        });
    }

    public function down(): void
    {
        Schema::table('fixture_lineups', function (Blueprint $table): void {
            $table->dropColumn('match_data_id');
        });
    }
};
