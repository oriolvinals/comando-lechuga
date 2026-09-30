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
 * after a purchase the clause is max(price, 1 M), and an initial-squad
 * player's is 5/3 of his value on the joining day; either follows the value
 * up and never goes down. Owners raise when the clause opens (unlock) or when
 * a shield expires, by a round amount, so a round excess at one of those
 * anchors is a certain raise and anything else a possible one. Only a buyout
 * inside the victim's lock (short of its last hour) is an accepted offer; after it, the amount paid is
 * the clause, whatever its shape (owners raise to round totals too). Raises
 * the user entered by hand always count, and replace what the history or the
 * inference would say for their holding.
 * PRIVATE: never exposed through /api.
 *
 * @phpstan-type Raises array{sure: int, possible: int}
 */
final class ClauseRaiseDetector
{
    public const int MIN_CLAUSE = 1_000_000;

    public const int LOCK_DAYS = 14;

    /** A buyout this close before the computed unlock is a clause payment: the real unlock can come earlier than purchase + 14 d. */
    public const int LOCK_BOUNDARY_TOLERANCE_MINUTES = 60;

    public const int ROUND_STEP = 100_000;

    public const int ROUND_TOLERANCE = 1_000;

    public const float NOISE_RATIO = 0.005;

    /** An initial-squad clause starts at this multiple of the player's value on the joining day. */
    public const int INITIAL_CLAUSE_NUMERATOR = 5;

    public const int INITIAL_CLAUSE_DENOMINATOR = 3;

    /** @var array<int, Raises> */
    private array $raises = [];

    /** @var array<int, list<array{at: CarbonImmutable, raise: int}>> manager id => each certain raise and its moment */
    private array $sureMoments = [];

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
        $this->sureMoments = [];
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

        foreach ($this->history as $rows) {
            $rows->where('source', ClauseSnapshotSource::Manual)
                ->each(fn (ManagerPlayerClauseSnapshot $entry) => $this->add($entry->season_manager_id, 'sure', $entry->raise_amount, $entry->captured_at));
        }

        /** @var array<int, array{manager_id: int, base: int, since: CarbonImmutable}> $holdings */
        $holdings = [];

        foreach ($moves as $move) {
            $playerId = (int) $move->player_id;
            $amount = (int) $move->amount;

            if ($move->type === SeasonActivityType::Buyout && $move->target_season_manager_id !== null) {
                $victimId = $move->target_season_manager_id;
                $holding = $this->holding($holdings[$playerId] ?? null, $victimId, $playerId, $season);

                if ($holding !== null && !$this->isOffer($holding['since'], $move->occurred_at)) {
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
     * his; otherwise an initial-squad holding since he joined, whose clause
     * starts at 5/3 of the player's value that day. When the player moved after
     * the joining (a gap in the feed), the holding starts at that move instead,
     * with the value that day as base. Null when that value is unknown: without
     * market history any clause would read as a phantom raise.
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
        $isInitialSquad = true;

        if (isset($this->lastMoveAt[$playerId]) && $this->lastMoveAt[$playerId]->greaterThan($since)) {
            $since = $this->lastMoveAt[$playerId];
            $isInitialSquad = false;
        }

        $value = $this->valueAt($playerId, $since);

        if ($value === null) {
            return null;
        }

        $base = $isInitialSquad ? intdiv($value * self::INITIAL_CLAUSE_NUMERATOR, self::INITIAL_CLAUSE_DENOMINATOR) : $value;

        return ['base' => max(self::MIN_CLAUSE, $base), 'since' => $since];
    }

    /**
     * An accepted offer between managers: only a buyout inside the victim's
     * lock, short of its last hour (the computed unlock may run late).
     */
    private function isOffer(CarbonImmutable $since, CarbonImmutable $at): bool
    {
        return $at->lessThan($since->addDays(self::LOCK_DAYS)->subMinutes(self::LOCK_BOUNDARY_TOLERANCE_MINUTES));
    }

    /**
     * Exact raises from the sync history when there is one, and the inference
     * before it. A holding with manual raises (already counted, whatever the
     * holding) skips the inference and every sync jump they explain.
     */
    private function holdingRaises(int $managerId, int $playerId, int $base, CarbonImmutable $since, int $clause, CarbonImmutable $at): void
    {
        $history = $this->holdingHistory($managerId, $playerId, $since, $at);
        $manual = $history->filter(fn (ManagerPlayerClauseSnapshot $snapshot): bool => $snapshot->source === ClauseSnapshotSource::Manual)->values();
        $snapshots = $history->filter(fn (ManagerPlayerClauseSnapshot $snapshot): bool => $snapshot->source === ClauseSnapshotSource::Sync)->values();

        if ($manual->isNotEmpty()) {
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
     * Each jump between consecutive sync rows is an exact raise, except the
     * ones a manual entry explains: for each manual entry, the nearest jump
     * within 24 h, whatever its amount (the user's figure already counts).
     *
     * @param  Collection<int, ManagerPlayerClauseSnapshot>  $snapshots
     * @param  Collection<int, ManagerPlayerClauseSnapshot>  $manual
     */
    private function syncJumps(int $managerId, Collection $snapshots, Collection $manual): void
    {
        /** @var array<int, array{at: CarbonImmutable, jump: int}> $jumps */
        $jumps = [];

        foreach ($snapshots->sliding(2) as $pair) {
            /** @var array{0: ManagerPlayerClauseSnapshot, 1: ManagerPlayerClauseSnapshot} $rows */
            $rows = $pair->values()->all();
            [$previous, $current] = $rows;
            $jump = $current->buyout_clause - max($previous->buyout_clause, $current->market_value);

            if ($jump > $current->buyout_clause * self::NOISE_RATIO) {
                $jumps[] = ['at' => $current->captured_at, 'jump' => $jump];
            }
        }

        foreach ($manual as $entry) {
            $nearest = null;

            foreach ($jumps as $index => $candidate) {
                $hours = abs($entry->captured_at->diffInHours($candidate['at']));

                if ($hours <= 24 && ($nearest === null || $hours < $nearest['hours'])) {
                    $nearest = ['index' => $index, 'hours' => $hours];
                }
            }

            if ($nearest !== null) {
                unset($jumps[$nearest['index']]);
            }
        }

        foreach ($jumps as $candidate) {
            $this->add($managerId, 'sure', $candidate['jump'], $candidate['at']);
        }
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
                $this->add($managerId, 'sure', $raise, $anchor);

                return;
            }
        }

        $moment = $unlock->lessThan($at) ? $unlock : $at;
        $this->add($managerId, 'possible', $clause - $this->reference($playerId, $base, $since, $moment), $moment);
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

    /**
     * The certain raises of the last forSeason() run that happened after a
     * moment: they are not in a cash snapshot captured then. Call forSeason()
     * first; before it this is always 0.
     */
    public function sureRaisedAfter(int $managerId, CarbonImmutable $moment): int
    {
        return array_sum(array_map(
            fn (array $sure): int => $sure['at']->greaterThan($moment) ? $sure['raise'] : 0,
            $this->sureMoments[$managerId] ?? [],
        ));
    }

    /** @param 'sure'|'possible' $bucket */
    private function add(int $managerId, string $bucket, int $raise, CarbonImmutable $at): void
    {
        $this->raises[$managerId] ??= ['sure' => 0, 'possible' => 0];
        $this->raises[$managerId][$bucket] += $raise;

        if ($bucket === 'sure') {
            $this->sureMoments[$managerId][] = ['at' => $at, 'raise' => $raise];
        }
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
