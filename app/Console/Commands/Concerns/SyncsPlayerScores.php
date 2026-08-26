<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Enums\PlayerStatus;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\PlayerScore;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

trait SyncsPlayerScores
{
    /**
     * @param  array<int, int>  $weekNumbers
     *
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    private function syncPlayerScoresForWeeks(Season $season, array $weekNumbers, LaLigaFantasyConnector $connector): int
    {
        $players = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->get()
            ->keyBy('fantasy_id');
        $teams = Team::query()->get()->keyBy('fantasy_id');
        $fixtures = Fixture::query()->where('season_id', $season->id)->get()->keyBy('fantasy_id');
        $scoresSynchronized = 0;

        foreach ($weekNumbers as $weekNumber) {
            $weekStats = $connector->getWeekStats($weekNumber)->throw()->json();

            $scoresSynchronized += DB::transaction(fn (): int => $this->syncWeek(
                $weekStats,
                $players,
                $teams,
                $fixtures,
            ));
        }

        return $scoresSynchronized;
    }

    /**
     * @param  array<array-key, mixed>  $weekStats
     * @param  Collection<int, Player>  $players
     * @param  Collection<int, Team>  $teams
     * @param  Collection<int, Fixture>  $fixtures
     */
    private function syncWeek(array $weekStats, Collection $players, Collection $teams, Collection $fixtures): int
    {
        $synchronized = 0;

        foreach ($weekStats as $fixtureData) {
            if (!is_array($fixtureData) || !isset($fixtureData['id'])) {
                continue;
            }

            /** @var Fixture|null $fixture */
            $fixture = $fixtures->get((int) $fixtureData['id']);

            if ($fixture === null) {
                continue;
            }

            $localPlayers = $fixtureData['local']['players'] ?? null;
            $visitorPlayers = $fixtureData['visitor']['players'] ?? null;

            if (!is_array($localPlayers) || !is_array($visitorPlayers)) {
                continue;
            }

            foreach ([...$localPlayers, ...$visitorPlayers] as $playerData) {
                if (
                    !is_array($playerData)
                    || !isset($playerData['id'], $playerData['teamId'], $playerData['weekPoints'])
                ) {
                    continue;
                }

                /** @var Player|null $player */
                $player = $players->get((int) $playerData['id']);

                /** @var Team|null $team */
                $team = $teams->get((int) $playerData['teamId']);

                if ($player === null || $team === null) {
                    continue;
                }

                PlayerScore::query()->updateOrCreate(
                    [
                        'player_id' => $player->id,
                        'fixture_id' => $fixture->id,
                    ],
                    [
                        'team_id' => $team->id,
                        'points' => (int) $playerData['weekPoints'],
                    ],
                );

                $synchronized++;
            }
        }

        return $synchronized;
    }
}
