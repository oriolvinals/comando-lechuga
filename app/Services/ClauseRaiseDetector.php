<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ClauseSnapshotSource;
use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\ManagerPlayerClauseSnapshot;
use App\Models\PlayerMarket;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Money managers spent raising buyout clauses (cost = half the raise).
 * Exact from the clause history when it exists; otherwise inferred:
 * after a purchase the clause is max(price, 1 M), follows the value up and
 * never goes down. Owners raise when the clause opens (unlock) or when a
 * shield expires, by a round amount, so a round excess at one of those
 * anchors is a certain raise and anything else a possible one. Buyouts
 * inside the victim's lock or for a round total are accepted offers, not
 * clause payments. Raises the user entered by hand are authoritative for
 * their holding. PRIVATE: never exposed through /api.
 *
 * @phpstan-type Raises array{sure: int, possible: int}
 */
final class ClauseRaiseDetector
{
    public const int MIN_CLAUSE = 1_000_000;

    public const int LOCK_DAYS = 14;

    public const int ROUND_STEP = 100_000;

    public const int ROUND_TOLERANCE = 1_000;

    public const float NOISE_RATIO = 0.005;

    /** @var array<int, Raises> */
    private array $raises = [];

    /** @var array<int, list<array{0: string, 1: int}>> player id => [Y-m-d, value] ordered by date */
    private array $values = [];

    /** @var array<int, array<int, list<CarbonImmutable>>> manager id => player id => shield moments */
    private array $shields = [];

    /** @var array<string, Collection<int, ManagerPlayerClauseSnapshot>> "manager:player" => clause history, sync and manual rows */
    private array $history = [];

    /** @var array<int, CarbonImmutable> manager id => joined_league */
    private array $joinedAt = [];

    /** @var array<int, CarbonImmutable> player id => latest acquisition or sale seen so far */
    private array $lastMoveAt = [];

    /**
     * @return array<int, Raises> season manager id => raised amounts
     */
    public function forSeason(Season $season, CarbonImmutable $now): array
    {
        $this->raises = [];
        $this->lastMoveAt = [];
        $activities = Activity::query()->where('season_id', $season->id)->orderBy('occurred_at')->orderBy('id')->get();
        $this->joinedAt = $activities
            ->where('type', SeasonActivityType::JoinedLeague)
            ->unique('source_season_manager_id')
            ->mapWithKeys(fn (Activity $joined): array => [$joined->source_season_manager_id => $joined->occurred_at])
            ->all();
        $this->shields = [];

        foreach ($activities->where('type', SeasonActivityType::Shield)->whereNotNull('player_id') as $shield) {
            $this->shields[$shield->source_season_manager_id][(int) $shield->player_id][] = $shield->occurred_at;
        }

        $moves = $activities->filter(fn (Activity $activity): bool => $activity->player_id !== null
            && in_array($activity->type, [SeasonActivityType::Signing, SeasonActivityType::Buyout, SeasonActivityType::Sale], true));
        $owned = ManagerPlayer::query()
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get();
        $this->values = $this->marketValues(array_values(array_unique([
            ...$moves->map(fn (Activity $move): int => (int) $move->player_id)->all(),
            ...$owned->map(fn (ManagerPlayer $entry): int => $entry->player_id)->all(),
        ])));
        $this->history = ManagerPlayerClauseSnapshot::query()
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->orderBy('captured_at')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ManagerPlayerClauseSnapshot $snapshot): string => "{$snapshot->season_manager_id}:{$snapshot->player_id}")
            ->all();

        /** @var array<int, array{manager_id: int, base: int, since: CarbonImmutable}> $holdings */
        $holdings = [];

        foreach ($moves as $move) {
            $playerId = (int) $move->player_id;
            $amount = (int) $move->amount;

            if ($move->type === SeasonActivityType::Buyout && $move->target_season_manager_id !== null) {
                $victimId = $move->target_season_manager_id;
                $holding = $this->holding($holdings[$playerId] ?? null, $victimId, $playerId, $season);

                if ($holding !== null && !$this->isOffer($amount, $holding['since'], $move->occurred_at)) {
                    $this->holdingRaises($victimId, $playerId, $holding['base'], $holding['since'], $amount, $move->occurred_at);
                }
            }

            $this->lastMoveAt[$playerId] = $move->occurred_at;

            if ($move->type === SeasonActivityType::Sale) {
                unset($holdings[$playerId]);

                continue;
            }

            $holdings[$playerId] = ['manager_id' => $move->source_season_manager_id, 'base' => max(self::MIN_CLAUSE, $amount), 'since' => $move->occurred_at];
        }

        foreach ($owned as $entry) {
            $holding = $this->holding($holdings[$entry->player_id] ?? null, $entry->season_manager_id, $entry->player_id, $season);

            if ($holding !== null) {
                $this->holdingRaises($entry->season_manager_id, $entry->player_id, $holding['base'], $holding['since'], $entry->buyout_clause, $now);
            }
        }

        return $this->raises;
    }

    /**
     * The manager's current holding of the player: the last purchase when it is
     * his; otherwise an initial-squad holding since he joined (or since the
     * player's last move, whichever is later) with its value that day as base.
     * Null when that value is unknown: without market history any clause would
     * read as a phantom raise.
     *
     * @param  array{manager_id: int, base: int, since: CarbonImmutable}|null  $holding
     * @return array{base: int, since: CarbonImmutable}|null
     */
    private function holding(?array $holding, int $managerId, int $playerId, Season $season): ?array
    {
        if ($holding !== null && $holding['manager_id'] === $managerId) {
            return ['base' => $holding['base'], 'since' => $holding['since']];
        }

        $since = $this->joinedAt[$managerId] ?? $season->start_date;

        if (isset($this->lastMoveAt[$playerId]) && $this->lastMoveAt[$playerId]->greaterThan($since)) {
            $since = $this->lastMoveAt[$playerId];
        }

        $value = $this->valueAt($playerId, $since);

        return $value === null ? null : ['base' => max(self::MIN_CLAUSE, $value), 'since' => $since];
    }

    /** An accepted offer between managers: inside the victim's lock, or a round total. */
    private function isOffer(int $amount, CarbonImmutable $since, CarbonImmutable $at): bool
    {
        return $at->lessThan($since->addDays(self::LOCK_DAYS)) || $this->isRound($amount, 1);
    }

    /**
     * The user's manual raises when the holding has any (authoritative: no
     * inference, and a sync jump they already explain is not counted again);
     * otherwise exact raises from the sync history when there is one, and the
     * inference before it.
     */
    private function holdingRaises(int $managerId, int $playerId, int $base, CarbonImmutable $since, int $clause, CarbonImmutable $at): void
    {
        $history = $this->holdingHistory($managerId, $playerId, $since, $at);
        $manual = $history->filter(fn (ManagerPlayerClauseSnapshot $snapshot): bool => $snapshot->source === ClauseSnapshotSource::Manual)->values();
        $snapshots = $history->filter(fn (ManagerPlayerClauseSnapshot $snapshot): bool => $snapshot->source === ClauseSnapshotSource::Sync)->values();

        if ($manual->isNotEmpty()) {
            $manual->each(fn (ManagerPlayerClauseSnapshot $entry) => $this->add($managerId, 'sure', $entry->raise_amount));
            $this->syncJumps($managerId, $snapshots, $manual);

            return;
        }

        if ($snapshots->isEmpty()) {
            $this->infer($managerId, $playerId, $base, $since, $clause, $at);

            return;
        }

        $first = $snapshots->first();
        $this->infer($managerId, $playerId, $base, $since, $first->buyout_clause, $first->captured_at);
        $this->syncJumps($managerId, $snapshots, collect());
    }

    /**
     * Each jump between consecutive sync rows is an exact raise, unless a
     * manual entry within 24 h reaches the same clause (already counted).
     *
     * @param  Collection<int, ManagerPlayerClauseSnapshot>  $snapshots
     * @param  Collection<int, ManagerPlayerClauseSnapshot>  $manual
     */
    private function syncJumps(int $managerId, Collection $snapshots, Collection $manual): void
    {
        $snapshots->sliding(2)->each(function (Collection $pair) use ($managerId, $manual): void {
            /** @var array{0: ManagerPlayerClauseSnapshot, 1: ManagerPlayerClauseSnapshot} $rows */
            $rows = $pair->values()->all();
            [$previous, $current] = $rows;
            $jump = $current->buyout_clause - max($previous->buyout_clause, $current->market_value);
            $explainedByManual = $manual->contains(fn (ManagerPlayerClauseSnapshot $entry): bool => $entry->buyout_clause === $current->buyout_clause
                && abs($entry->captured_at->diffInHours($current->captured_at)) <= 24);

            if ($jump > $current->buyout_clause * self::NOISE_RATIO && !$explainedByManual) {
                $this->add($managerId, 'sure', $jump);
            }
        });
    }

    /**
     * The clause history of one holding only: rows captured from its start to
     * its end, so a sell-and-rebuy never compares the clause of one holding
     * with the next and reads the new price as a paid raise.
     *
     * @return Collection<int, ManagerPlayerClauseSnapshot>
     */
    private function holdingHistory(int $managerId, int $playerId, CarbonImmutable $since, CarbonImmutable $until): Collection
    {
        return ($this->history["{$managerId}:{$playerId}"] ?? collect())
            ->filter(fn (ManagerPlayerClauseSnapshot $snapshot): bool => $snapshot->captured_at->betweenIncluded($since, $until))
            ->values();
    }

    private function infer(int $managerId, int $playerId, int $base, CarbonImmutable $since, int $clause, CarbonImmutable $at): void
    {
        if ($clause - $this->reference($playerId, $base, $since, $at) <= $clause * self::NOISE_RATIO) {
            return;
        }

        $unlock = $since->addDays(self::LOCK_DAYS);
        $anchors = [$unlock, $unlock->subDay()];

        foreach ($this->shields[$managerId][$playerId] ?? [] as $shieldedAt) {
            if ($shieldedAt->betweenIncluded($since, $at)) {
                $anchors[] = $shieldedAt;
                $anchors[] = $shieldedAt->addDay();
            }
        }

        foreach ($anchors as $anchor) {
            if ($anchor->greaterThan($at)) {
                continue;
            }

            $raise = $clause - $this->reference($playerId, $base, $since, $anchor);

            if ($raise > $clause * self::NOISE_RATIO && $this->isRound($raise, self::ROUND_TOLERANCE)) {
                $this->add($managerId, 'sure', $raise);

                return;
            }
        }

        $this->add($managerId, 'possible', $clause - $this->reference($playerId, $base, $since, $unlock->lessThan($at) ? $unlock : $at));
    }

    /** The automatic clause at a moment: the base, raised by the highest value since the holding began. */
    private function reference(int $playerId, int $base, CarbonImmutable $since, CarbonImmutable $until): int
    {
        $from = $since->toDateString();
        $to = $until->toDateString();
        $max = 0;

        foreach ($this->values[$playerId] ?? [] as [$date, $value]) {
            if ($date >= $from && $date <= $to) {
                $max = max($max, $value);
            }
        }

        return max($base, $max);
    }

    private function valueAt(int $playerId, CarbonImmutable $day): ?int
    {
        $found = null;

        foreach ($this->values[$playerId] ?? [] as [$date, $value]) {
            if ($date <= $day->toDateString()) {
                $found = $value;
            }
        }

        return $found;
    }

    private function isRound(int $amount, int $tolerance): bool
    {
        return abs($amount - (int) round($amount / self::ROUND_STEP) * self::ROUND_STEP) <= $tolerance;
    }

    /** @param 'sure'|'possible' $bucket */
    private function add(int $managerId, string $bucket, int $raise): void
    {
        $this->raises[$managerId] ??= ['sure' => 0, 'possible' => 0];
        $this->raises[$managerId][$bucket] += $raise;
    }

    /**
     * @param  list<int>  $playerIds
     * @return array<int, list<array{0: string, 1: int}>>
     */
    private function marketValues(array $playerIds): array
    {
        $values = [];

        PlayerMarket::query()
            ->whereIn('player_id', $playerIds)
            ->orderBy('date')
            ->get(['player_id', 'date', 'value'])
            ->each(function (PlayerMarket $market) use (&$values): void {
                $values[(int) $market->player_id][] = [$market->date->toDateString(), $market->value];
            });

        return $values;
    }
}
