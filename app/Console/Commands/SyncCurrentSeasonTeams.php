<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-teams')]
#[Description('Synchronize the current season teams from worldcup26.ir, enriched with Fantasy data')]
class SyncCurrentSeasonTeams extends Command
{
    /**
     * fantasy_id => worldcup26.ir team id — validated 1:1 against real data
     * (same mapping previously used by the now-deleted LinkMatchDataTeams).
     *
     * @var array<int, int>
     */
    private const array TEAM_MAP = [
        2 => 1068,
        3 => 93,
        4 => 83,
        5 => 244,
        6 => 85,
        7 => 3751,
        8 => 88,
        9 => 2922,
        11 => 1538,
        12 => 99,
        13 => 97,
        14 => 101,
        15 => 86,
        16 => 89,
        17 => 243,
        18 => 94,
        20 => 102,
        21 => 96,
        26 => 90,
        49 => 87,
    ];

    /** @var array<int, int> worldcup26 id => fantasy_id, the inverse of TEAM_MAP */
    private array $matchDataIdToFantasyId;

    public function __construct()
    {
        parent::__construct();

        $this->matchDataIdToFantasyId = array_flip(self::TEAM_MAP);
    }

    /**
     * @throws Throwable
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    public function handle(Worldcup26Connector $worldcup26Connector, LaLigaFantasyConnector $fantasyConnector): int
    {
        $season = Season::current();

        $this->info('Syncing teams from worldcup26...');
        $created = $this->syncFromWorldcup26($worldcup26Connector, $season);

        $this->info('Enriching teams from Fantasy...');
        $enriched = $this->enrichFromFantasy($fantasyConnector);

        $this->info("{$created} teams synced from worldcup26, {$enriched} enriched from Fantasy.");

        return self::SUCCESS;
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    private function syncFromWorldcup26(Worldcup26Connector $connector, Season $season): int
    {
        /** @var array<int, array{name: string, shortName: string}> $teamsById */
        $teamsById = [];
        $pageIndex = 1;

        do {
            $this->line("  fixtures page {$pageIndex}...");

            $response = $connector->getFixtures($pageIndex)->throw()->json();
            $events = is_array($response['events'] ?? null) ? $response['events'] : [];
            $pageCount = (int) ($response['pageCount'] ?? 1);

            foreach ($events as $event) {
                if (!is_array($event) || ($event['season']['slug'] ?? null) !== $season->match_data_season_slug) {
                    continue;
                }

                $competitors = $event['competitions'][0]['competitors'] ?? [];

                if (!is_array($competitors)) {
                    continue;
                }

                foreach ($competitors as $competitor) {
                    $team = $competitor['team'] ?? null;

                    if (!is_array($team) || !isset($team['id'])) {
                        continue;
                    }

                    $matchDataId = (int) $team['id'];
                    $teamsById[$matchDataId] = [
                        'name' => (string) ($team['name'] ?? ''),
                        'shortName' => (string) ($team['shortDisplayName'] ?? ''),
                    ];
                }
            }

            $pageIndex++;
        } while ($pageIndex <= $pageCount);

        $teamIds = [];
        $skipped = [];

        foreach ($teamsById as $matchDataId => $teamData) {
            $fantasyId = $this->matchDataIdToFantasyId[$matchDataId] ?? null;

            if ($fantasyId === null) {
                $skipped[] = $teamData['name'] !== '' ? $teamData['name'] : (string) $matchDataId;

                continue;
            }

            $team = Team::query()->updateOrCreate(
                ['match_data_id' => $matchDataId],
                [
                    'name' => $teamData['name'],
                    'short_name' => $teamData['shortName'],
                    'fantasy_id' => $fantasyId,
                ],
            );

            $teamIds[] = $team->id;
        }

        if ($skipped !== []) {
            $this->warn('Teams with no TEAM_MAP entry — update the map: '.implode(', ', $skipped));
        }

        if ($teamIds === []) {
            $this->warn('No teams matched the current season slug — leaving season_team untouched.');

            return 0;
        }

        $season->teams()->sync($teamIds);

        return count($teamIds);
    }

    /**
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    private function enrichFromFantasy(LaLigaFantasyConnector $connector): int
    {
        $enriched = 0;

        foreach ($connector->getTeamInfo()->throw()->json() as $teamData) {
            $fantasyId = (int) $teamData['id'];
            $team = Team::query()->where('fantasy_id', $fantasyId)->first();

            if ($team === null) {
                continue;
            }

            $badgeColor = $teamData['badgeColor'] ?? null;

            $team->update([
                'main_name' => (string) $teamData['mainName'],
                'name' => (string) $teamData['name'],
                'slug' => (string) $teamData['slug'],
                'short_name' => (string) $teamData['shortName'],
                'logo' => $this->storeBadge($connector, $fantasyId, is_string($badgeColor) ? $badgeColor : null),
            ]);

            $enriched++;
        }

        return $enriched;
    }

    /**
     * @throws FatalRequestException
     * @throws Throwable
     * @throws RequestException
     */
    private function storeBadge(LaLigaFantasyConnector $connector, int $fantasyId, ?string $badgeUrl): string
    {
        if ($badgeUrl === null) {
            return '';
        }

        $path = "images/team/{$fantasyId}.png";
        $contents = $connector->getAsset($badgeUrl)->throw()->body();
        $disk = Storage::disk('public');

        if (!$disk->exists($path) || !hash_equals(hash('sha256', $disk->get($path)), hash('sha256', $contents))) {
            $disk->put($path, $contents);
        }

        return $path;
    }
}
