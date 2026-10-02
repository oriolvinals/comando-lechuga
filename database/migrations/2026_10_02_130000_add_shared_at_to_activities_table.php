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
        Schema::table('activities', function (Blueprint $table): void {
            $table->timestamp('shared_at')->nullable()->after('occurred_at');

            $table->index(['type', 'shared_at', 'occurred_at']);
        });

        $this->backfillSharedAt();
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table): void {
            $table->dropIndex(['type', 'shared_at', 'occurred_at']);
            $table->dropColumn('shared_at');
        });
    }

    /**
     * Every signing already synced counts as shared (the earlier stories went out by hand), so the first scheduled
     * run only publishes signings that arrive after the deploy.
     */
    public function backfillSharedAt(): void
    {
        DB::table('activities')
            ->where('type', 'signing')
            ->whereNull('shared_at')
            ->update(['shared_at' => DB::raw('occurred_at')]);
    }
};
