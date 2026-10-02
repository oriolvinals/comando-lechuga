<?php

declare(strict_types=1);

use App\Services\ManagerInstagramAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('season_managers', function (Blueprint $table): void {
            $table->string('instagram_username')->default('')->after('logo');
        });

        $this->backfillInstagramUsernames();
    }

    public function down(): void
    {
        Schema::table('season_managers', function (Blueprint $table): void {
            $table->dropColumn('instagram_username');
        });
    }

    public function backfillInstagramUsernames(): void
    {
        foreach (ManagerInstagramAccounts::ACCOUNTS as $fantasyUserId => $username) {
            DB::table('season_managers')
                ->where('fantasy_user_id', $fantasyUserId)
                ->update(['instagram_username' => $username]);
        }
    }
};
