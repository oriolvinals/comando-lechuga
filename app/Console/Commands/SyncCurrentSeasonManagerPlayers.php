<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ClauseSnapshotSource;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\ManagerBalanceSnapshot;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-manager-players')]
#[Description('Synchronize the current squad of each season manager from La Liga Fantasy')]
class SyncCurrentSeasonManagerPlayers extends Command
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
        $managersSynchronized = 0;
        $seasonManagers = SeasonManager::query()->where('season_id', $season->id)->get();

        $this->output->progressStart($seasonManagers->count());

        foreach ($seasonManagers as $seasonManager) {
            $this->output->progressAdvance();

            $managerData = $fantasyConnector
                ->getLeagueTeamWithLogin($loginConnector, $season->fantasy_id, $seasonManager->fantasy_id)
                ->json();

            $this->snapshotTeamMoney($seasonManager, $managerData['teamMoney'] ?? null);

            $players = $managerData['players'] ?? [];

            if (!is_array($players)) {
                continue;
            }

            DB::transaction(function () use ($seasonManager, $players): void {
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

                    $lockedUntil = CarbonImmutable::parse((string) $playerEntry['buyoutClauseLockedEndTime'])
                        ->setTimezone((string) config('app.timezone'));

                    ManagerPlayer::query()->updateOrCreate(
                        [
                            'season_manager_id' => $seasonManager->id,
                            'player_id' => $player->id,
                        ],
                        [
                            'buyout_clause' => (int) ($playerEntry['buyoutClause'] ?? 0),
                            'buyout_clause_locked_until' => $lockedUntil,
                            'shielded' => (bool) ($playerEntry['isShielded'] ?? false),
                            'shielded_until' => isset($playerEntry['shieldedEndDate'])
                                ? CarbonImmutable::parse((string) $playerEntry['shieldedEndDate'])
                                    ->setTimezone((string) config('app.timezone'))
                                : null,
                        ],
                    );

                    $this->snapshotClause(
                        $seasonManager,
                        $player,
                        (int) ($playerEntry['buyoutClause'] ?? 0),
                        $lockedUntil,
                        (int) ($playerMasterData['marketValue'] ?? 0),
                    );

                    $currentPlayerIds[] = $player->id;
                }

                ManagerPlayer::query()
                    ->where('season_manager_id', $seasonManager->id)
                    ->whereNotIn('player_id', $currentPlayerIds)
                    ->delete();
            });

            $managersSynchronized++;
        }

        $this->output->progressFinish();

        $this->info($managersSynchronized.' season manager squads synchronized.');

        return self::SUCCESS;
    }

    /**
     * Keeps the connected account's real cash, at most once per manager and
     * hour (the command runs every minute). Rivals come back with a null
     * `teamMoney` and are skipped.
     */
    private function snapshotTeamMoney(SeasonManager $seasonManager, mixed $teamMoney): void
    {
        if (!is_numeric($teamMoney)) {
            return;
        }

        $hasRecentSnapshot = ManagerBalanceSnapshot::query()
            ->where('season_manager_id', $seasonManager->id)
            ->where('captured_at', '>', now()->subHour())
            ->exists();

        if ($hasRecentSnapshot) {
            return;
        }

        ManagerBalanceSnapshot::query()->create([
            'season_manager_id' => $seasonManager->id,
            'money' => (int) $teamMoney,
            'captured_at' => now(),
        ]);
    }

    /**
     * Keeps the clause history of each holding: a new row only when the
     * clause or its lock changed since the last sync row. Manual rows never
     * affect change detection.
     */
    private function snapshotClause(SeasonManager $seasonManager, Player $player, int $clause, CarbonImmutable $lockedUntil, int $marketValue): void
    {
        $latest = ManagerPlayerClauseSnapshot::query()
            ->where('season_manager_id', $seasonManager->id)
            ->where('player_id', $player->id)
            ->where('source', ClauseSnapshotSource::Sync)
            ->latest('captured_at')
            ->latest('id')
            ->first();

        if ($latest instanceof ManagerPlayerClauseSnapshot
            && $latest->buyout_clause === $clause
            && $latest->buyout_clause_locked_until->equalTo($lockedUntil)) {
            return;
        }

        ManagerPlayerClauseSnapshot::query()->create([
            'season_manager_id' => $seasonManager->id,
            'player_id' => $player->id,
            'buyout_clause' => $clause,
            'buyout_clause_locked_until' => $lockedUntil,
            'market_value' => $marketValue,
            'captured_at' => now(),
        ]);
    }
}
