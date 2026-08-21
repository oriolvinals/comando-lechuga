<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SeasonActivityType;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\LaLigaFantasy\LaLigaLoginConnector;
use App\Models\Player;
use App\Models\Season;
use App\Models\SeasonActivity;
use App\Models\SeasonTeam;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-activity')]
#[Description('Synchronize the current season activity history from La Liga Fantasy')]
class SyncCurrentSeasonActivity extends Command
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
        $activitiesSynchronized = 0;
        $page = 0;

        do {
            $activities = $fantasyConnector
                ->getLeagueActivityWithLogin($loginConnector, $season->fantasy_id, $page)
                ->json();

            if ($activities === []) {
                break;
            }

            $activitiesSynchronized += DB::transaction(function () use ($season, $activities): int {
                $synchronized = 0;

                foreach ($activities as $activityData) {
                    if (!is_array($activityData) || !isset($activityData['activityTypeId'], $activityData['id'], $activityData['createdAt'])) {
                        continue;
                    }

                    try {
                        $type = SeasonActivityType::fromFantasyId((int) $activityData['activityTypeId']);
                    } catch (InvalidArgumentException) {
                        continue;
                    }

                    $sourceSeasonTeam = SeasonTeam::query()
                        ->where('season_id', $season->id)
                        ->where('fantasy_user_id', (int) ($activityData['user1Id'] ?? 0))
                        ->first();

                    if ($sourceSeasonTeam === null) {
                        continue;
                    }

                    $targetSeasonTeam = isset($activityData['user2Id'])
                        ? SeasonTeam::query()
                            ->where('season_id', $season->id)
                            ->where('fantasy_user_id', (int) $activityData['user2Id'])
                            ->first()
                        : null;

                    $player = isset($activityData['playerMasterId'])
                        ? Player::query()->where('fantasy_id', (int) $activityData['playerMasterId'])->first()
                        : null;

                    SeasonActivity::query()->updateOrCreate(
                        [
                            'season_id' => $season->id,
                            'fantasy_id' => (int) $activityData['id'],
                        ],
                        [
                            'type' => $type,
                            'source_season_team_id' => $sourceSeasonTeam->id,
                            'target_season_team_id' => $targetSeasonTeam?->id,
                            'player_id' => $player?->id,
                            'amount' => isset($activityData['amount']) ? (int) $activityData['amount'] : null,
                            'week_number' => isset($activityData['weekNumber']) ? (int) $activityData['weekNumber'] : null,
                            'occurred_at' => CarbonImmutable::parse((string) $activityData['createdAt'])
                                ->setTimezone((string) config('app.timezone')),
                        ],
                    );

                    $synchronized++;
                }

                return $synchronized;
            });

            $page++;
        } while (true);

        $this->info($activitiesSynchronized.' season activities synchronized.');

        return self::SUCCESS;
    }
}
