<?php

declare(strict_types=1);

namespace App\Services\ValueForecast;

use App\Enums\FixtureState;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What the day's forecast depends on, as one hash: the reference day's
 * published values, every finished match of the season with each lineup's
 * minutes and points, and each player's team and status. It changes when
 * the values are published, when a match finishes or gets its points or
 * minutes late, and when a player changes team or leaves the league: the
 * moments the forecast must be redone. Fixture reschedules are left out.
 *
 * Day boundaries never go through the database clock: the reference date
 * is compared with DATE columns as a `Y-m-d` string (as a half-open range,
 * so SQLite's `Y-m-d H:i:s` dates match too), and match timestamps are
 * hashed as read, so the MySQL session timezone can't shift a day.
 */
final class ValueForecastFingerprint
{
    public function for(Season $season, string $referenceDate): string
    {
        $hash = hash_init('sha1');
        hash_update($hash, $referenceDate);

        foreach (
            DB::table('player_markets')
                ->where('date', '>=', $referenceDate)
                ->where('date', '<', CarbonImmutable::parse($referenceDate, 'UTC')->addDay()->toDateString())
                ->orderBy('player_id')
                ->select(['player_id', 'value'])
                ->cursor() as $market
        ) {
            hash_update($hash, "|m{$market->player_id}:{$market->value}");
        }

        foreach (
            DB::table('fixtures')
                ->where('season_id', $season->id)
                ->where('state', FixtureState::Finished->value)
                ->orderBy('id')
                ->select(['id', 'date', 'team_local_id', 'team_guest_id'])
                ->cursor() as $fixture
        ) {
            hash_update($hash, "|f{$fixture->id}:{$fixture->date}:{$fixture->team_local_id}:{$fixture->team_guest_id}");
        }

        foreach (
            DB::table('fixture_lineups')
                ->join('fixtures', 'fixtures.id', '=', 'fixture_lineups.fixture_id')
                ->where('fixtures.season_id', $season->id)
                ->where('fixtures.state', FixtureState::Finished->value)
                ->whereNotNull('fixture_lineups.player_id')
                ->orderBy('fixture_lineups.id')
                ->select(['fixture_lineups.id', 'fixture_lineups.fixture_id', 'fixture_lineups.player_id', 'fixture_lineups.fantasy_points', 'fixture_lineups.fantasy_stats'])
                ->cursor() as $lineup
        ) {
            $minutes = ValueForecastFeatures::minutes($lineup->fantasy_stats);
            $points = $lineup->fantasy_points ?? 'null';
            hash_update($hash, "|l{$lineup->id}:{$lineup->fixture_id}:{$lineup->player_id}:{$minutes}:{$points}");
        }

        foreach (DB::table('players')->orderBy('id')->select(['id', 'team_id', 'status'])->cursor() as $player) {
            hash_update($hash, "|p{$player->id}:{$player->team_id}:{$player->status}");
        }

        return hash_final($hash);
    }
}
