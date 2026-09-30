<?php

declare(strict_types=1);

namespace App\Services\Prizes;

use App\Enums\SeasonActivityType;
use App\Models\Activity;
use App\Models\ManagerPlayer;
use App\Models\Season;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Who owned each player, and when, rebuilt from the season's activity:
 * a `signing` opens the source's spell, a `sale` closes it (the player goes
 * to the market), a `buyout` closes the target's spell and opens the
 * source's. When a move gives a player up (sale or buyout) that nobody
 * held, the giver got him in the initial allocation: he held him since he
 * joined the league (`joined_league`), or since the previous spell ended if
 * that is later. A late joiner can be allocated players released to the
 * market before he joined. A `manager_players` row whose player the replay
 * leaves unheld (no activity at all, or released before a late joiner got
 * him) is an allocation too, open until now. If the replay already leaves
 * him held, the replay wins: it knows when he moved, `manager_players` does
 * not, and any disagreement is a data gap the squad check surfaces.
 * Spells are [from, to): `from` null = since the season started, `to`
 * exclusive, null = still open.
 *
 * @phpstan-type Spell array{season_manager_id: int, from: CarbonImmutable|null, to: CarbonImmutable|null}
 */
final class SquadHistory
{
    /**
     * @param  array<int, list<Spell>>  $spellsByPlayer
     */
    private function __construct(private readonly array $spellsByPlayer) {}

    public static function forSeason(Season $season): self
    {
        $spellsByPlayer = [];

        /** @var array<int, CarbonImmutable> $joinedAt */
        $joinedAt = Activity::query()
            ->where('season_id', $season->id)
            ->where('type', SeasonActivityType::JoinedLeague)
            ->orderBy('occurred_at')
            ->get(['source_season_manager_id', 'occurred_at'])
            ->unique('source_season_manager_id')
            ->mapWithKeys(fn (Activity $joined): array => [$joined->source_season_manager_id => $joined->occurred_at])
            ->all();

        Activity::query()
            ->where('season_id', $season->id)
            ->whereIn('type', [SeasonActivityType::Signing, SeasonActivityType::Sale, SeasonActivityType::Buyout])
            ->whereNotNull('player_id')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['type', 'player_id', 'source_season_manager_id', 'target_season_manager_id', 'occurred_at'])
            ->groupBy('player_id')
            ->each(function (Collection $moves, int|string $playerId) use (&$spellsByPlayer, $joinedAt): void {
                $spellsByPlayer[(int) $playerId] = self::replay($moves, $joinedAt);
            });

        ManagerPlayer::query()
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->get(['season_manager_id', 'player_id'])
            ->each(function (ManagerPlayer $owned) use (&$spellsByPlayer, $joinedAt): void {
                $spells = $spellsByPlayer[$owned->player_id] ?? [];
                $last = $spells === [] ? null : $spells[array_key_last($spells)];

                if ($last !== null && $last['to'] === null) {
                    return;
                }

                $spells[] = [
                    'season_manager_id' => $owned->season_manager_id,
                    'from' => self::allocatedAt($joinedAt[$owned->season_manager_id] ?? null, $last['to'] ?? null),
                    'to' => null,
                ];
                $spellsByPlayer[$owned->player_id] = $spells;
            });

        return new self($spellsByPlayer);
    }

    /**
     * @param  Collection<int, Activity>  $moves
     * @param  array<int, CarbonImmutable>  $joinedAt
     * @return list<Spell>
     */
    private static function replay(Collection $moves, array $joinedAt): array
    {
        $spells = [];
        $open = null;

        foreach ($moves as $move) {
            $giver = match ($move->type) {
                SeasonActivityType::Sale => $move->source_season_manager_id,
                SeasonActivityType::Buyout => $move->target_season_manager_id,
                default => null,
            };
            $taker = $move->type === SeasonActivityType::Sale ? null : $move->source_season_manager_id;

            if ($open === null && $giver !== null) {
                $releasedAt = $spells === [] ? null : $spells[array_key_last($spells)]['to'];
                $open = ['season_manager_id' => $giver, 'from' => self::allocatedAt($joinedAt[$giver] ?? null, $releasedAt, $move->occurred_at), 'to' => null];
            }

            if ($open !== null && ($giver !== null || $taker !== null)) {
                $open['to'] = $move->occurred_at;
                $spells[] = $open;
                $open = null;
            }

            if ($taker !== null) {
                $open = ['season_manager_id' => $taker, 'from' => $move->occurred_at, 'to' => null];
            }
        }

        if ($open !== null) {
            $spells[] = $open;
        }

        return $spells;
    }

    /**
     * When an allocated player reached his manager: at joining, but never
     * before the previous owner let him go nor after he gave him up.
     */
    private static function allocatedAt(?CarbonImmutable $joinedAt, ?CarbonImmutable $releasedAt, ?CarbonImmutable $givenUpAt = null): ?CarbonImmutable
    {
        $from = $releasedAt !== null && ($joinedAt === null || $joinedAt < $releasedAt) ? $releasedAt : $joinedAt;

        return $givenUpAt !== null && $from !== null && $givenUpAt < $from ? $givenUpAt : $from;
    }

    /**
     * @return list<Spell>
     */
    public function spells(int $playerId): array
    {
        return $this->spellsByPlayer[$playerId] ?? [];
    }

    /**
     * @return list<int>
     */
    public function playerIds(): array
    {
        return array_keys($this->spellsByPlayer);
    }

    public function ownerAt(int $playerId, CarbonInterface $at): ?int
    {
        foreach ($this->spells($playerId) as $spell) {
            if (($spell['from'] === null || $spell['from'] <= $at) && ($spell['to'] === null || $at < $spell['to'])) {
                return $spell['season_manager_id'];
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function squadAt(int $seasonManagerId, CarbonInterface $at): array
    {
        return array_values(array_filter(
            $this->playerIds(),
            fn (int $playerId): bool => $this->ownerAt($playerId, $at) === $seasonManagerId,
        ));
    }
}
