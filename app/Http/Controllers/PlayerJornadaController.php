<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Services\PlayerFichaScores;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

/**
 * One player's jornada sheet (`/jugadores/{player}/jornadas/{fixture}`), as
 * JSON: what HqPlayerStatsModal needs for a lineup row of the active season,
 * in the ficha's own score shape. Lists that only show points (players list,
 * market, rosters, squads) fetch it on click instead of carrying every
 * slot's stats and fixture in their page props.
 */
class PlayerJornadaController extends Controller
{
    use AttachesCurrentPlayerSeason;

    public function __invoke(Player $player, Fixture $fixture, PlayerFichaScores $fichaScores): JsonResponse
    {
        $season = Season::current();
        $score = $fichaScores->forPlayer($player, $season, $fixture->id)[0] ?? null;

        abort_if($score === null, 404);

        // The sheet's position tag is the active season's.
        $this->attachCurrentSeason(new Collection([$player]), $season->id);

        return response()->json([
            'player' => Arr::only($player->toArray(), ['id', 'nickname', 'image', 'position']),
            'score' => $score,
        ]);
    }
}
