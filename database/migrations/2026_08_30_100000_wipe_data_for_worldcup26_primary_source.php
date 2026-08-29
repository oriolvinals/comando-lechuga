<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const array TABLES_TO_WIPE = [
        'activities',
        'fixture_events',
        'fixture_lineups',
        'fixtures',
        'manager_lineup_players',
        'manager_lineups',
        'manager_players',
        'market_players',
        'player_markets',
        'player_scores',
        'player_seasons',
        'players',
        'season_team',
        'teams',
    ];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach (self::TABLES_TO_WIPE as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->delete();
            }
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Data-only migration — deleted rows cannot be restored.
    }
};
