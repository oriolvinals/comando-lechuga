<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Integrations\LaLigaFantasy\LaLigaFantasyConnector;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:sync-teams')]
#[Description('Synchronize the current season teams from La Liga Fantasy')]
class SyncCurrentSeasonTeams extends Command
{
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

    /**
     * @throws Throwable
     * @throws FatalRequestException
     * @throws RequestException
     * @throws JsonException
     */
    public function handle(LaLigaFantasyConnector $connector): int
    {
        $season = Season::current();

        $teams = [];

        foreach ($connector->getTeamInfo()->throw()->json() as $teamData) {
            $fantasyId = (int)$teamData['id'];
            $badgeColor = $teamData['badgeColor'] ?? null;

            $teams[] = [
                'fantasy_id' => $fantasyId,
                'main_name' => (string)$teamData['mainName'],
                'name' => (string)$teamData['name'],
                'slug' => (string)$teamData['slug'],
                'short_name' => (string)$teamData['shortName'],
                'logo' => $this->storeBadge($connector, $fantasyId, is_string($badgeColor) ? $badgeColor : null),
                'match_data_id' => self::TEAM_MAP[$fantasyId] ?? $fantasyId,
            ];
        }

        $teamIds = DB::transaction(function () use ($season, $teams): array {
            $teamIds = [];

            foreach ($teams as $teamData) {
                $team = Team::query()
                    ->updateOrCreate(
                        ['fantasy_id' => $teamData['fantasy_id']],
                        [
                            'main_name' => $teamData['main_name'],
                            'name' => $teamData['name'],
                            'slug' => $teamData['slug'],
                            'short_name' => $teamData['short_name'],
                            'logo' => $teamData['logo'],
                            'match_data_id' => $teamData['match_data_id'],
                        ],
                    );

                $teamIds[] = $team->id;
            }

            $season->teams()->sync($teamIds);

            return $teamIds;
        });

        $this->info(count($teamIds).' teams synchronized.');

        return self::SUCCESS;
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
