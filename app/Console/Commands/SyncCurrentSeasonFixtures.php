<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FixtureState;
use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Fixture;
use App\Models\Season;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-fixtures')]
#[Description('Synchronize the current season fixtures from La Liga Fantasy')]
class SyncCurrentSeasonFixtures extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $season = Season::query()
            ->where('current', true)
            ->sole();
        $teams = $season->teams()->get()->keyBy('fantasy_id');
        $fixtures = [];

        foreach (range(1, 38) as $weekNumber) {
            foreach ($connector->getFixtures($weekNumber)->throw()->json() as $fixtureData) {
                /** @var Team|null $localTeam */
                $localTeam = $teams->get((int)$fixtureData['localId']);

                /** @var Team|null $guestTeam */
                $guestTeam = $teams->get((int)$fixtureData['visitorId']);

                if ($localTeam === null || $guestTeam === null) {
                    continue;
                }

                $fixtures[] = [
                    'fantasy_id' => (int)$fixtureData['id'],
                    'season_id' => $season->id,
                    'week_number' => $weekNumber,
                    'date' => CarbonImmutable::parse($fixtureData['matchDate'])
                        ->setTimezone((string) config('app.timezone')),
                    'team_local_id' => $localTeam->id,
                    'team_guest_id' => $guestTeam->id,
                    'local_score' => $fixtureData['localScore'] === null ? null : (int)$fixtureData['localScore'],
                    'guest_score' => $fixtureData['visitorScore'] === null ? null : (int)$fixtureData['visitorScore'],
                    'state' => FixtureState::fromFantasyId((int)$fixtureData['matchState']),
                ];
            }
        }

        $fixtureIds = DB::transaction(function () use ($season, $fixtures): array {
            $fixtureIds = [];

            foreach ($fixtures as $fixtureData) {
                $fixtureIds[] = Fixture::query()
                    ->updateOrCreate([
                        'fantasy_id' => $fixtureData['fantasy_id'],
                        'season_id' => $season->id,
                    ], $fixtureData)->id;
            }

            return $fixtureIds;
        });

        $this->info(count($fixtureIds).' fixtures synchronized.');

        return self::SUCCESS;
    }
}
