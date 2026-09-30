<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Models\Season;
use App\Services\Prizes\Concerns\ListsSeasonManagers;
use App\Services\SeasonClock;

/**
 * The player who went through most hands (distinct owners, the initial one
 * included; a same-day spell counts). Among his owners, the prize goes to
 * whoever held him the most finished jornadas, a jornada being held by the
 * owner at its lineup lock (SeasonClock::lineupLock, the start of the
 * jornada's main block). Several players can tie.
 *
 * @phpstan-type OwnedPlayerCandidate array{player_id: int, chain: list<int>, owners: list<int>, transfers: int, on_market: bool, weeks_held: array<int, int>, winners: list<int>}
 */
final class MostOwnedPlayer implements PrizeCalculator
{
    use ListsSeasonManagers;

    public function __construct(private readonly SeasonClock $clock) {}

    /**
     * @param  SquadHistory|null  $history  the season's, when the caller already built it
     * @return list<OwnedPlayerCandidate>
     */
    public function candidates(Season $season, ?SquadHistory $history = null): array
    {
        $history ??= SquadHistory::forSeason($season);
        $ownersByPlayer = [];

        foreach ($history->playerIds() as $playerId) {
            $ownersByPlayer[$playerId] = array_values(array_unique(array_column($history->spells($playerId), 'season_manager_id')));
        }

        $mostOwners = $ownersByPlayer === [] ? 0 : max(array_map('count', $ownersByPlayer));

        if ($mostOwners < 2) {
            return [];
        }

        $locks = [];

        foreach ($this->clock->finishedWeekNumbers($season) as $week) {
            $lock = $this->clock->lineupLock($season, $week);

            if ($lock !== null) {
                $locks[] = $lock;
            }
        }

        $candidates = [];

        foreach ($ownersByPlayer as $playerId => $owners) {
            if (count($owners) !== $mostOwners) {
                continue;
            }

            $spells = $history->spells($playerId);
            $weeksHeld = array_fill_keys($owners, 0);

            foreach ($locks as $lock) {
                $owner = $history->ownerAt($playerId, $lock);

                if ($owner !== null) {
                    $weeksHeld[$owner]++;
                }
            }

            $max = max($weeksHeld);
            $lastSpell = end($spells);
            $onMarket = $lastSpell !== false && $lastSpell['to'] !== null;

            $candidates[] = [
                'player_id' => $playerId,
                'chain' => array_column($spells, 'season_manager_id'),
                'owners' => $owners,
                'transfers' => count($spells) - 1 + ($onMarket ? 1 : 0),
                'on_market' => $onMarket,
                'weeks_held' => $weeksHeld,
                'winners' => $max === 0 ? [] : array_keys(array_filter($weeksHeld, fn (int $held): bool => $held === $max)),
            ];
        }

        return $candidates;
    }

    public function rows(Season $season): array
    {
        return $this->rowsFor($season, $this->candidates($season));
    }

    /**
     * Each manager's best jornadas held among the given candidates.
     *
     * @param  list<OwnedPlayerCandidate>  $candidates
     * @return list<PrizeRow>
     */
    public function rowsFor(Season $season, array $candidates): array
    {
        return array_map(function (int $id) use ($candidates): PrizeRow {
            $best = null;

            foreach ($candidates as $candidate) {
                if (isset($candidate['weeks_held'][$id]) && ($best === null || $candidate['weeks_held'][$id] > $best['value'])) {
                    $best = ['value' => $candidate['weeks_held'][$id], 'player_id' => $candidate['player_id']];
                }
            }

            return $best === null
                ? new PrizeRow($id, null)
                : new PrizeRow($id, $best['value'], ['player_id' => $best['player_id']]);
        }, $this->managerIds($season));
    }
}
