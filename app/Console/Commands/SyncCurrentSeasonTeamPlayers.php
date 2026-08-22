<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonTeam;
use App\Models\SeasonTeamPlayer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-team-players')]
#[Description('Synchronize the current squad of each season team from La Liga Fantasy')]
class SyncCurrentSeasonTeamPlayers extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(
        LaLigaLoginConnector $loginConnector,
        LaLigaFantasyConnector $fantasyConnector,
    ): int {
        $season = Season::current();
        $teamsSynchronized = 0;

        foreach (SeasonTeam::query()->where('season_id', $season->id)->get() as $seasonTeam) {
            $teamData = $fantasyConnector
                ->getLeagueTeamWithLogin($loginConnector, $season->fantasy_id, $seasonTeam->fantasy_id)
                ->json();

            $players = $teamData['players'] ?? [];

            if (!is_array($players)) {
                continue;
            }

            DB::transaction(function () use ($seasonTeam, $players): void {
                $currentPlayerIds = [];

                foreach ($players as $playerEntry) {
                    $playerMasterData = is_array($playerEntry) ? $playerEntry['playerMaster'] ?? null : null;

                    if (!is_array($playerMasterData)) {
                        continue;
                    }

                    $player = Player::query()
                        ->where('fantasy_id', (int) $playerMasterData['id'])
                        ->first();

                    if ($player === null) {
                        continue;
                    }

                    SeasonTeamPlayer::query()->updateOrCreate(
                        [
                            'season_team_id' => $seasonTeam->id,
                            'player_id' => $player->id,
                        ],
                        [
                            'buyout_clause' => (int) ($playerEntry['buyoutClause'] ?? 0),
                            'buyout_clause_locked_until' => CarbonImmutable::parse((string) $playerEntry['buyoutClauseLockedEndTime'])
                                ->setTimezone((string) config('app.timezone')),
                            'shielded' => (bool) ($playerEntry['isShielded'] ?? false),
                        ],
                    );

                    $currentPlayerIds[] = $player->id;
                }

                SeasonTeamPlayer::query()
                    ->where('season_team_id', $seasonTeam->id)
                    ->whereNotIn('player_id', $currentPlayerIds)
                    ->delete();
            });

            $teamsSynchronized++;
        }

        $this->info($teamsSynchronized.' season team squads synchronized.');

        return self::SUCCESS;
    }
}
