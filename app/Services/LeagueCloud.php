<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PlayerStatus;
use App\Http\Controllers\Concerns\AttachesCurrentPlayerSeason;
use App\Models\ManagerPlayer;
use App\Models\Player;
use App\Models\PlayerMarket;
use App\Models\Season;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * One light row per listed league player of the season (~556): the dot
 * clouds of the comparator's "Pistas", the picker's search and the hover
 * cards. Ranks, medians and "supera al X %" are computed on the client over
 * these rows. Loading the whole league's value trend is expensive, so the
 * rows are cached for 15 minutes. `start_probability` is for the comparator's first
 * upcoming jornada (`$fromWeek`), like the compared players' own figure.
 *
 * @phpstan-type LeagueCloudRow array{id: int, name: string, image: string, position: string|null, team_short: string, owner_id: int|null, points: int, average_points: float, ppm: float|null, start_probability: int|null, value_trend_30d: float|null, value: int, difference: int}
 */
final class LeagueCloud
{
    use AttachesCurrentPlayerSeason;

    public const int CACHE_MINUTES = 15;

    /** Snapshots loaded per player: enough for the 30-day multiple, without the whole season. */
    public const int VALUE_HISTORY_DAYS = 60;

    public function __construct(
        private readonly PlayerMarketMetrics $marketMetrics,
        private readonly StartProbabilities $startProbabilities,
    ) {}

    /**
     * @return list<LeagueCloudRow>
     */
    public function rows(Season $season, int $fromWeek): array
    {
        /** @var list<LeagueCloudRow> */
        return Cache::remember(
            $this->cacheKey($season, $fromWeek),
            now()->addMinutes(self::CACHE_MINUTES),
            fn (): array => $this->build($season, $fromWeek),
        );
    }

    public function cacheKey(Season $season, int $fromWeek): string
    {
        return sprintf('league-cloud:%d:%s:j%d', $season->id, now()->format('Y-m-d-H'), $fromWeek);
    }

    /**
     * A confirmed lineup is the truth (100 or 0); before that, FútbolFantasy's
     * probability; null without data (never 0 %).
     *
     * @param  array{probability: int|null, confirmed_starter: bool|null, ...}|null  $start
     */
    public static function startValue(?array $start): ?int
    {
        if ($start === null) {
            return null;
        }

        if ($start['confirmed_starter'] !== null) {
            return $start['confirmed_starter'] ? 100 : 0;
        }

        return $start['probability'];
    }

    /**
     * @return list<LeagueCloudRow>
     */
    private function build(Season $season, int $fromWeek): array
    {
        /** @var Collection<int, Player> $players */
        $players = Player::query()
            ->with('team')
            ->whereNotNull('fantasy_id')
            ->where('status', '!=', PlayerStatus::OutOfLeague)
            ->whereHas('seasons', fn ($query) => $query->where('season_id', $season->id))
            ->get();

        $this->attachCurrentSeason($players, $season->id);

        $players = $players->sortByDesc(fn (Player $player): int => $player->points)->values();
        $ids = $players->pluck('id')->all();

        $owners = ManagerPlayer::query()
            ->whereIn('player_id', $ids)
            ->whereHas('seasonManager', fn ($query) => $query->where('season_id', $season->id))
            ->pluck('season_manager_id', 'player_id');

        $pointsPerMillion = $this->marketMetrics->pointsPerMillionForPlayers($players, $season);
        $starts = $this->startProbabilities->forPlayersNextFixture($players, $season, $fromWeek);

        $historyByPlayer = PlayerMarket::query()
            ->whereIn('player_id', $ids)
            ->where('date', '>=', now()->subDays(self::VALUE_HISTORY_DAYS)->toDateString())
            ->orderBy('date')
            ->get()
            ->groupBy('player_id');

        return array_values($players
            ->map(fn (Player $player): array => [
                'id' => $player->id,
                'name' => $player->nickname,
                'image' => $player->image !== '' ? asset('storage/'.$player->image) : '',
                'position' => $player->position?->value,
                'team_short' => $player->team->short_name,
                'owner_id' => $owners->has($player->id) ? (int) $owners->get($player->id) : null,
                'points' => $player->points,
                'average_points' => (float) $player->average_points,
                'ppm' => $pointsPerMillion[$player->id]['value'] ?? null,
                'start_probability' => ($starts[$player->id]['week_number'] ?? null) === $fromWeek ? self::startValue($starts[$player->id]) : null,
                'value_trend_30d' => $this->marketMetrics->valueTrend(
                    $player->market_value,
                    $historyByPlayer->get($player->id) ?? new Collection,
                )['multiple'] ?? null,
                'value' => $player->market_value,
                'difference' => $player->market_value_difference,
            ])
            ->all());
    }
}
