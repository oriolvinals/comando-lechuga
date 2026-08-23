<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const array TEAMS = [
        37394676 => ['id' => 1, 'fantasy_user_id' => 2035022, 'name' => 'Cruza FC', 'logo' => 'images/teams/37394676.png', 'season_id' => 1, 'primary_color' => '#8a0607', 'secondary_color' => '#171210'],
        37394771 => ['id' => 2, 'fantasy_user_id' => 10160264, 'name' => 'CID  F.C', 'logo' => 'images/teams/37394771.png', 'season_id' => 1, 'primary_color' => '#2f5fd8', 'secondary_color' => '#8a1228'],
        37394521 => ['id' => 3, 'fantasy_user_id' => 6392099, 'name' => 'Gauchitos F.C', 'logo' => 'images/teams/37394521.png', 'season_id' => 1, 'primary_color' => '#f0c419', 'secondary_color' => '#0f3d24'],
        37393880 => ['id' => 4, 'fantasy_user_id' => 6572651, 'name' => 'DukeBlack9', 'logo' => 'images/teams/37393880.png', 'season_id' => 1, 'primary_color' => '#3d7dfd', 'secondary_color' => '#0a0a0a'],
        37397960 => ['id' => 5, 'fantasy_user_id' => 2890485, 'name' => 'DUBI F.C', 'logo' => 'images/teams/37397960.png', 'season_id' => 1, 'primary_color' => '#7a2fd6', 'secondary_color' => '#0a0a0a'],
        37397110 => ['id' => 6, 'fantasy_user_id' => 11757415, 'name' => 'Ariobretxa', 'logo' => 'images/teams/37397110.png', 'season_id' => 1, 'primary_color' => '#5c1f8a', 'secondary_color' => '#f0c419'],
        38444080 => ['id' => 7, 'fantasy_user_id' => 2442084, 'name' => 'planuky', 'logo' => 'images/teams/38444080.png', 'season_id' => 1, 'primary_color' => '#12a0ad', 'secondary_color' => '#0d2b46'],
    ];

    public function up(): void
    {
        if (!app()->environment('production')) {
            return;
        }

        foreach (self::TEAMS as $fantasyId => $data) {
            DB::table('season_teams')->insert([
                'id' => $data['id'],
                'fantasy_id' => $fantasyId,
                'fantasy_user_id' => $data['fantasy_user_id'],
                'name' => $data['name'],
                'logo' => $data['logo'],
                'season_id' => $data['season_id'],
                'primary_color' => $data['primary_color'],
                'secondary_color' => $data['secondary_color'],
            ]);
        }
    }

    public function down(): void
    {
        if (!app()->environment('production')) {
            return;
        }

        DB::table('season_teams')
            ->whereIn('fantasy_id', array_keys(self::TEAMS))
            ->delete();
    }
};
