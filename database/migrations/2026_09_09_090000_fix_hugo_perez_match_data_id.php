<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PLAYER_MAP had fantasy_id 3212 (Hugo Pérez, RAC) pointing at worldcup26.ir
 * athlete 417250, which is actually a different player (Hugo Martín). This
 * corrects the player's match_data_id, reverts the fixture_lineups/fixture_events
 * rows that got wrongly attributed to Hugo Pérez via the old id, and backfills
 * the ones waiting on the correct id (3100788).
 */
return new class extends Migration
{
    private const int WRONG_MATCH_DATA_ID = 417250;

    private const int CORRECT_MATCH_DATA_ID = 3100788;

    private const int FANTASY_ID = 3212;

    private const string WRONG_ID_PLAYER_NAME = 'Hugo Martín';

    public function up(): void
    {
        DB::transaction(function (): void {
            $player = DB::table('players')->where('fantasy_id', self::FANTASY_ID)->first();

            if ($player === null) {
                return;
            }

            DB::table('fixture_lineups')
                ->where('match_data_id', self::WRONG_MATCH_DATA_ID)
                ->where('player_id', $player->id)
                ->update(['player_id' => null, 'unresolved_name' => self::WRONG_ID_PLAYER_NAME]);

            DB::table('fixture_events')
                ->where('match_data_id', self::WRONG_MATCH_DATA_ID)
                ->where('player_id', $player->id)
                ->update(['player_id' => null, 'unresolved_name' => self::WRONG_ID_PLAYER_NAME]);

            if ((int) $player->match_data_id === self::WRONG_MATCH_DATA_ID) {
                DB::table('players')->where('id', $player->id)->update(['match_data_id' => self::CORRECT_MATCH_DATA_ID]);
            }

            DB::table('fixture_lineups')
                ->where('match_data_id', self::CORRECT_MATCH_DATA_ID)
                ->whereNull('player_id')
                ->update(['player_id' => $player->id, 'unresolved_name' => null]);

            DB::table('fixture_events')
                ->where('match_data_id', self::CORRECT_MATCH_DATA_ID)
                ->whereNull('player_id')
                ->update(['player_id' => $player->id, 'unresolved_name' => null]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $player = DB::table('players')->where('fantasy_id', self::FANTASY_ID)->first();

            if ($player === null) {
                return;
            }

            DB::table('fixture_lineups')
                ->where('match_data_id', self::CORRECT_MATCH_DATA_ID)
                ->where('player_id', $player->id)
                ->update(['player_id' => null]);

            DB::table('fixture_events')
                ->where('match_data_id', self::CORRECT_MATCH_DATA_ID)
                ->where('player_id', $player->id)
                ->update(['player_id' => null]);

            if ((int) $player->match_data_id === self::CORRECT_MATCH_DATA_ID) {
                DB::table('players')->where('id', $player->id)->update(['match_data_id' => self::WRONG_MATCH_DATA_ID]);
            }

            DB::table('fixture_lineups')
                ->where('match_data_id', self::WRONG_MATCH_DATA_ID)
                ->whereNull('player_id')
                ->update(['player_id' => $player->id, 'unresolved_name' => null]);

            DB::table('fixture_events')
                ->where('match_data_id', self::WRONG_MATCH_DATA_ID)
                ->whereNull('player_id')
                ->update(['player_id' => $player->id, 'unresolved_name' => null]);
        });
    }
};
