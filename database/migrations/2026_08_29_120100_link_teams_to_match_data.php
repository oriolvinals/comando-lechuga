<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * worldcup26.ir team id => our teams.id — see the design spec for how
     * this was derived (validated 1:1 against real data, no ambiguity).
     *
     * @var array<int, int>
     */
    private const array TEAM_MAP = [
        83 => 3,
        86 => 13,
        243 => 15,
        244 => 4,
        96 => 18,
        1068 => 1,
        97 => 11,
        88 => 7,
        2922 => 8,
        102 => 17,
        90 => 19,
        87 => 20,
        101 => 12,
        85 => 5,
        94 => 16,
        99 => 10,
        1538 => 9,
        3751 => 6,
        89 => 14,
        93 => 2,
    ];

    public function up(): void
    {
        foreach (self::TEAM_MAP as $matchDataId => $teamId) {
            DB::table('teams')->where('id', $teamId)->update(['match_data_id' => $matchDataId]);
        }
    }

    public function down(): void
    {
        DB::table('teams')->whereIn('match_data_id', array_keys(self::TEAM_MAP))->update(['match_data_id' => null]);
    }
};
