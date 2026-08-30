<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PlayerStatus;
use App\Http\Integrations\Worldcup26\Worldcup26Connector;
use App\Models\Fixture;
use App\Models\Player;
use App\Models\Season;
use App\Models\Team;
use App\Services\MatchDataPlayerMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Throwable;

#[Signature('season:link-match-data-players')]
#[Description('Link current season players to their worldcup26.ir athlete id, via already-linked fixtures\' rosters')]
class LinkMatchDataPlayers extends Command
{
    /**
     * @throws FatalRequestException
     * @throws JsonException
     * @throws RequestException
     * @throws Throwable
     */
    public function handle(Worldcup26Connector $connector, MatchDataPlayerMatcher $matcher): int
    {
        $season = Season::current();

        $fixtures = Fixture::query()
            ->where('season_id', $season->id)
            ->whereNotNull('match_data_id')
            ->get();

        $linked = 0;

        /** @var array<int, true> $claimedMatchDataIds */
        $claimedMatchDataIds = Player::query()->whereNotNull('match_data_id')->pluck('match_data_id')
            ->mapWithKeys(fn (int $id): array => [$id => true])->all();

        foreach ($fixtures as $fixture) {
            $event = $connector->getEvent($fixture->match_data_id)->throw()->json();
            $rosters = is_array($event['rosters'] ?? null) ? $event['rosters'] : [];

            foreach ($rosters as $rosterEntry) {
                $teamMatchDataId = (int) ($rosterEntry['team']['id'] ?? 0);
                $team = Team::query()->where('match_data_id', $teamMatchDataId)->first();

                if ($team === null) {
                    continue;
                }

                $players = Player::query()
                    ->where('team_id', $team->id)
                    ->whereNull('match_data_id')
                    ->get();

                if ($players->isEmpty()) {
                    continue;
                }

                $rosterEntries = is_array($rosterEntry['roster'] ?? null) ? $rosterEntry['roster'] : [];

                $roster = collect($rosterEntries)
                    ->filter(fn ($entry): bool => is_array($entry) && isset($entry['athlete']['id'], $entry['athlete']['displayName']))
                    ->map(fn (array $entry): array => [
                        'id' => (int) $entry['athlete']['id'],
                        'displayName' => (string) $entry['athlete']['displayName'],
                    ])
                    ->filter(fn (array $entry): bool => !isset($claimedMatchDataIds[$entry['id']]))
                    ->values()
                    ->all();

                $matches = $matcher->match($players, $roster);

                DB::transaction(function () use ($players, $matches, &$linked, &$claimedMatchDataIds): void {
                    foreach ($players as $player) {
                        if (isset($matches[$player->id])) {
                            $player->update(['match_data_id' => $matches[$player->id]]);
                            $claimedMatchDataIds[$matches[$player->id]] = true;
                            $linked++;
                        }
                    }
                });
            }
        }

        $this->info($linked.' players linked.');

        $unresolved = Player::query()
            ->whereIn('team_id', $season->teams()->select('teams.id'))
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->whereNull('match_data_id')
            ->get(['nickname', 'team_id']);

        if ($unresolved->isNotEmpty()) {
            $this->warn('Unresolved — needs manual review: '.$unresolved
                ->map(fn (Player $player): string => "{$player->nickname} (team #{$player->team_id})")
                ->implode(', '));
        }

        return self::SUCCESS;
    }
}
