<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\Worldcup26\Worldcup26Connector;
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

#[Signature('season:link-match-data-fixtures')]
#[Description('Link the current season fixtures to their worldcup26.ir match id')]
class LinkMatchDataFixtures extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $connector): int
    {
        $season = Season::current();
        $teamsByMatchDataId = Team::query()->whereNotNull('match_data_id')->get()->keyBy('match_data_id');

        /** @var array<int, array{matchDataId: int, homeTeamId: int, awayTeamId: int, date: string}> $remoteFixtures */
        $remoteFixtures = [];
        $pageIndex = 1;

        do {
            $this->info("Fetching worldcup26 fixtures page {$pageIndex}...");

            $page = $connector->getFixtures($pageIndex)->throw()->json();
            $events = is_array($page['events'] ?? null) ? $page['events'] : [];

            foreach ($events as $event) {
                $competitors = $event['competitions'][0]['competitors'] ?? null;

                if (!is_array($competitors)) {
                    continue;
                }

                $home = null;
                $away = null;

                foreach ($competitors as $competitor) {
                    if (($competitor['homeAway'] ?? null) === 'home') {
                        $home = (int) ($competitor['team']['id'] ?? 0);
                    } elseif (($competitor['homeAway'] ?? null) === 'away') {
                        $away = (int) ($competitor['team']['id'] ?? 0);
                    }
                }

                if ($home === null || $away === null || !isset($event['id'], $event['date'])) {
                    continue;
                }

                $remoteFixtures[] = [
                    'matchDataId' => (int) $event['id'],
                    'homeTeamId' => $home,
                    'awayTeamId' => $away,
                    'date' => (string) $event['date'],
                ];
            }

            $pageCount = (int) ($page['pageCount'] ?? 1);
            $pageIndex++;
        } while ($pageIndex <= $pageCount);

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNull('match_data_id')
            ->get();

        $this->info("Matching {$fixtures->count()} unlinked fixtures against ".count($remoteFixtures).' worldcup26 fixtures...');

        $linked = DB::transaction(function () use ($fixtures, $remoteFixtures, $teamsByMatchDataId): int {
            $linked = 0;

            foreach ($fixtures as $fixture) {
                $homeMatchDataId = $teamsByMatchDataId->firstWhere('id', $fixture->team_local_id)?->match_data_id;
                $awayMatchDataId = $teamsByMatchDataId->firstWhere('id', $fixture->team_guest_id)?->match_data_id;

                if ($homeMatchDataId === null || $awayMatchDataId === null) {
                    continue;
                }

                $candidates = array_filter(
                    $remoteFixtures,
                    fn (array $remote): bool => $remote['homeTeamId'] === $homeMatchDataId
                        && $remote['awayTeamId'] === $awayMatchDataId
                        && abs(CarbonImmutable::parse($remote['date'])->diffInDays($fixture->date, absolute: true)) <= 1,
                );

                if (count($candidates) !== 1) {
                    continue;
                }

                $fixture->update(['match_data_id' => reset($candidates)['matchDataId']]);
                $linked++;
            }

            return $linked;
        });

        $this->info($linked.' fixtures linked.');

        return self::SUCCESS;
    }
}
