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
        $teamsByWc26Id = Team::query()->whereNotNull('wc26_id')->get()->keyBy('wc26_id');

        /** @var array<int, array{wc26Id: int, homeTeamId: int, awayTeamId: int, date: string}> $remoteFixtures */
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
                    'wc26Id' => (int) $event['id'],
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
            ->whereNull('wc26_id')
            ->get();

        $this->info("Matching {$fixtures->count()} unlinked fixtures against ".count($remoteFixtures).' worldcup26 fixtures...');

        $linked = DB::transaction(function () use ($fixtures, $remoteFixtures, $teamsByWc26Id): int {
            $linked = 0;

            foreach ($fixtures as $fixture) {
                $homeWc26Id = $teamsByWc26Id->firstWhere('id', $fixture->team_local_id)?->wc26_id;
                $awayWc26Id = $teamsByWc26Id->firstWhere('id', $fixture->team_guest_id)?->wc26_id;

                if ($homeWc26Id === null || $awayWc26Id === null) {
                    continue;
                }

                $candidates = array_filter(
                    $remoteFixtures,
                    fn (array $remote): bool => $remote['homeTeamId'] === $homeWc26Id
                        && $remote['awayTeamId'] === $awayWc26Id
                        && abs(CarbonImmutable::parse($remote['date'])->diffInDays($fixture->date, absolute: true)) <= 1,
                );

                if (count($candidates) !== 1) {
                    continue;
                }

                $fixture->update(['wc26_id' => reset($candidates)['wc26Id']]);
                $linked++;
            }

            return $linked;
        });

        $this->info($linked.' fixtures linked.');

        return self::SUCCESS;
    }
}
