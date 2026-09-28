<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\FutbolFantasyLinkRule;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\PlayerSeason;
use App\Models\Season;
use App\Models\Team;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Links the players of a FútbolFantasy team page to ours. First hit wins:
 *
 * 1. `players.futbolfantasy_id`, stored after the first successful link (any team).
 * 2. Same team + the exact LaLiga Fantasy market value FF prints
 *    (`data-valor-laliga-fantasy`) against our `player_markets` of the last
 *    3 days; a tie is narrowed by total points, then by position.
 * 3. Same team + normalised name: our nickname against FF's short name or
 *    the full name in its slug; failing that, our nickname without its
 *    initial ("T. Martínez" → "martinez") as a whole word of either.
 * 4. The manual map, for leftovers.
 *
 * Rules 2–4 store the FF id on the player, so later syncs link him by rule 1.
 * Each of our players is linked at most once per page, and anything
 * ambiguous stays unlinked — never guessed.
 */
class FutbolFantasyPlayerLinker
{
    private const int MARKET_WINDOW_DAYS = 3;

    /**
     * FútbolFantasy player id => our players.fantasy_id — filled in by hand
     * for the players the sync reports as "Unlinked". Document each entry:
     *   3078 => 2951, // T. Martínez - ALA
     *
     * @var array<int, int>
     */
    public const array PLAYER_MAP = [];

    /**
     * @param  array<int, int>  $playerMap  FútbolFantasy id => players.fantasy_id
     */
    public function __construct(private readonly array $playerMap = self::PLAYER_MAP) {}

    /**
     * @param  list<FutbolFantasyPlayer>  $ffPlayers
     * @return array<int, array{player: Player, rule: FutbolFantasyLinkRule}> keyed by FútbolFantasy id; unlinked ids are absent
     */
    public function link(Team $team, Season $season, array $ffPlayers): array
    {
        $ffIds = array_map(fn (FutbolFantasyPlayer $ffPlayer): int => $ffPlayer->futbolfantasyId, $ffPlayers);

        $storedById = Player::query()
            ->whereIn('futbolfantasy_id', $ffIds)
            ->get()
            ->keyBy('futbolfantasy_id');

        $candidates = Player::query()
            ->where('team_id', $team->id)
            ->whereNull('futbolfantasy_id')
            ->get();

        $candidateIds = $candidates->pluck('id')->all();

        $seasonRows = PlayerSeason::query()
            ->where('season_id', $season->id)
            ->whereIn('player_id', $candidateIds)
            ->get()
            ->keyBy('player_id');

        /** @var array<int, list<int>> $recentValues */
        $recentValues = [];

        PlayerMarket::query()
            ->whereIn('player_id', $candidateIds)
            ->whereDate('date', '>=', now()->subDays(self::MARKET_WINDOW_DAYS)->toDateString())
            ->get(['player_id', 'value'])
            ->each(function (PlayerMarket $market) use (&$recentValues): void {
                $recentValues[$market->player_id][] = $market->value;
            });

        /** @var array<int, array{player: Player, rule: FutbolFantasyLinkRule}> $links */
        $links = [];

        /** @var array<int, true> $taken our player ids already linked on this page */
        $taken = [];

        // Stored ids first, so a heuristic never claims a player another FF id already owns.
        foreach ($ffPlayers as $ffPlayer) {
            $stored = $storedById->get($ffPlayer->futbolfantasyId);

            if ($stored instanceof Player) {
                $links[$ffPlayer->futbolfantasyId] = ['player' => $stored, 'rule' => FutbolFantasyLinkRule::StoredId];
                $taken[$stored->id] = true;
            }
        }

        foreach ($ffPlayers as $ffPlayer) {
            if (isset($links[$ffPlayer->futbolfantasyId])) {
                continue;
            }

            $free = $candidates->reject(fn (Player $player): bool => isset($taken[$player->id]))->values();

            $link = $this->byMarketValue($ffPlayer, $free, $recentValues, $seasonRows)
                ?? $this->byName($ffPlayer, $free)
                ?? $this->byManualMap($ffPlayer, $taken);

            if ($link === null) {
                continue;
            }

            $link['player']->update(['futbolfantasy_id' => $ffPlayer->futbolfantasyId]);
            $links[$ffPlayer->futbolfantasyId] = $link;
            $taken[$link['player']->id] = true;
        }

        return $links;
    }

    /**
     * @param  Collection<int, Player>  $free
     * @param  array<int, list<int>>  $recentValues
     * @param  Collection<int, PlayerSeason>  $seasonRows  keyed by player id
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function byMarketValue(FutbolFantasyPlayer $ffPlayer, Collection $free, array $recentValues, Collection $seasonRows): ?array
    {
        if ($ffPlayer->marketValue <= 0) {
            return null;
        }

        $matches = $free
            ->filter(fn (Player $player): bool => in_array($ffPlayer->marketValue, $recentValues[$player->id] ?? [], true))
            ->values();
        $matches = $this->narrow($matches, fn (Player $player): bool => $seasonRows->get($player->id)?->points === $ffPlayer->totalPoints);
        $matches = $this->narrow($matches, fn (Player $player): bool => $ffPlayer->position !== null && $seasonRows->get($player->id)?->position === $ffPlayer->position);

        return $this->single($matches, FutbolFantasyLinkRule::MarketValue);
    }

    /**
     * @param  Collection<int, Player>  $free
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function byName(FutbolFantasyPlayer $ffPlayer, Collection $free): ?array
    {
        $names = array_values(array_unique(array_filter(
            [self::normalize($ffPlayer->name), self::normalize(str_replace('-', ' ', $ffPlayer->slug))],
            fn (string $name): bool => $name !== '',
        )));

        if ($names === []) {
            return null;
        }

        $matches = $free
            ->filter(fn (Player $player): bool => in_array(self::normalize($player->nickname), $names, true))
            ->values();

        if ($matches->isEmpty()) {
            $matches = $free
                ->filter(function (Player $player) use ($names): bool {
                    $surname = self::surname($player->nickname);

                    if (mb_strlen($surname) < 3) {
                        return false;
                    }

                    foreach ($names as $name) {
                        if (str_contains(" {$name} ", " {$surname} ")) {
                            return true;
                        }
                    }

                    return false;
                })
                ->values();
        }

        return $this->single($matches, FutbolFantasyLinkRule::Name);
    }

    /**
     * @param  array<int, true>  $taken
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function byManualMap(FutbolFantasyPlayer $ffPlayer, array $taken): ?array
    {
        $fantasyId = $this->playerMap[$ffPlayer->futbolfantasyId] ?? null;

        if ($fantasyId === null) {
            return null;
        }

        $player = Player::query()
            ->where('fantasy_id', $fantasyId)
            ->whereNull('futbolfantasy_id')
            ->first();

        if ($player === null || isset($taken[$player->id])) {
            return null;
        }

        return ['player' => $player, 'rule' => FutbolFantasyLinkRule::ManualMap];
    }

    /**
     * A tie-break: keeps only the matches passing $test, unless that would
     * rule every one of them out.
     *
     * @param  Collection<int, Player>  $matches
     * @param  callable(Player): bool  $test
     * @return Collection<int, Player>
     */
    private function narrow(Collection $matches, callable $test): Collection
    {
        if ($matches->count() <= 1) {
            return $matches;
        }

        $narrowed = $matches->filter($test)->values();

        return $narrowed->isEmpty() ? $matches : $narrowed;
    }

    /**
     * @param  Collection<int, Player>  $matches
     * @return array{player: Player, rule: FutbolFantasyLinkRule}|null
     */
    private function single(Collection $matches, FutbolFantasyLinkRule $rule): ?array
    {
        $player = $matches->count() === 1 ? $matches->first() : null;

        return $player instanceof Player ? ['player' => $player, 'rule' => $rule] : null;
    }

    /**
     * Lowercase ASCII words: accents, case and punctuation stripped ("Á. Carreras" → "a carreras").
     */
    private static function normalize(string $name): string
    {
        $words = (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name)));

        return trim((string) preg_replace('/\s+/', ' ', $words));
    }

    /**
     * Our nickname without its leading initials ("T. Martínez" → "martinez").
     */
    private static function surname(string $nickname): string
    {
        return (string) preg_replace('/^(?:[a-z] )+/', '', self::normalize($nickname));
    }
}
